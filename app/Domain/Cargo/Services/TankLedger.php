<?php

namespace App\Domain\Cargo\Services;

use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Cargo\Enums\MovementType;
use App\Domain\Cargo\Exceptions\CommodityMismatchException;
use App\Domain\Cargo\Exceptions\InsufficientStockException;
use App\Domain\Cargo\Exceptions\MovementNotReversibleException;
use App\Domain\Cargo\Exceptions\TankCapacityExceededException;
use App\Models\CargoMovement;
use App\Models\Tank;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single gateway for changing a tank's balance.
 *
 *  - Atomic: runs in a transaction holding a row lock on the tank, so two
 *    concurrent requests cannot both claim the same free space.
 *  - Idempotent: a repeated idempotency key returns the original movement.
 *  - Time-ordered: balances are always recomputed by replaying the ledger
 *    sorted by occurred_at, so late / out-of-order records are safe.
 */
class TankLedger
{
    public function __construct(private TransitLossCalculator $loss)
    {
    }

    public function record(
        int $tankId,
        MovementType $type,
        int $grossKg,
        CarbonInterface $occurredAt,
        string $idempotencyKey,
        ?string $reference = null,
        ?Commodity $commodity = null,
        array $links = [],   // optional FKs: vessel_discharge_id / delivery_order_id
    ): CargoMovement {
        if ($grossKg <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        try {
            return DB::transaction(function () use (
                $tankId, $type, $grossKg, $occurredAt, $idempotencyKey, $reference, $commodity, $links
            ) {
                $tank = Tank::query()->lockForUpdate()->findOrFail($tankId);

                if ($existing = CargoMovement::where('idempotency_key', $idempotencyKey)->first()) {
                    return $existing;
                }

                if ($commodity !== null && $commodity !== $tank->commodity) {
                    throw new CommodityMismatchException(sprintf(
                        'Tank %s holds %s, not %s.',
                        $tank->code, $tank->commodity->label(), $commodity->label()
                    ));
                }

                $loss = $type->isInbound() ? $this->loss->lossFor($grossKg) : 0;

                $movement = CargoMovement::create([
                    'tank_id' => $tank->id,
                    'type' => $type,
                    'reference' => $reference,
                    'gross_kg' => $grossKg,
                    'loss_kg' => $loss,
                    'net_kg' => $grossKg - $loss,
                    'occurred_at' => $occurredAt,
                    'idempotency_key' => $idempotencyKey,
                    ...$links,
                ]);

                // Throws (and rolls the insert back) if the timeline breaks a rule.
                $tank->current_volume_kg = $this->replay($tank);
                $tank->save();

                return $movement;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race on the same idempotency key: the other request won.
            return CargoMovement::where('idempotency_key', $idempotencyKey)->firstOrFail();
        }
    }

    /**
     * Cancel a movement by appending its mirror image. The ledger itself is never edited.
     * The reversal copies gross / loss / net exactly (no fresh 0.5% loss) and flips the direction,
     * so replay, operation totals and the tank balance all stay consistent.
     * Fails, changing nothing, if the tank could not legally hold the result.
     */
    public function reverse(CargoMovement $original, CarbonInterface $occurredAt, string $idempotencyKey): CargoMovement
    {
        return DB::transaction(function () use ($original, $occurredAt, $idempotencyKey) {
            $tank = Tank::query()->lockForUpdate()->findOrFail($original->tank_id);

            if ($existing = CargoMovement::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }
            if ($original->isReversal()) {
                throw new MovementNotReversibleException('A reversal cannot itself be reversed.');
            }
            if (CargoMovement::where('reverses_movement_id', $original->id)->exists()) {
                throw new MovementNotReversibleException("Movement #{$original->id} has already been reversed.");
            }

            $movement = CargoMovement::create([
                'tank_id' => $tank->id,
                'type' => $original->type->isInbound() ? MovementType::TruckDelivery : MovementType::VesselDischarge,
                'reference' => "Reversal of #{$original->id}",
                'gross_kg' => $original->gross_kg,
                'loss_kg' => $original->loss_kg,
                'net_kg' => $original->net_kg,
                'occurred_at' => $occurredAt,
                'idempotency_key' => $idempotencyKey,
                'vessel_discharge_id' => $original->vessel_discharge_id,
                'delivery_order_id' => $original->delivery_order_id,
                'reverses_movement_id' => $original->id,
            ]);

            $tank->current_volume_kg = $this->replay($tank);   // throws if the timeline would break
            $tank->save();

            return $movement;
        });
    }

    /**
     * Re-sort every movement by timestamp and walk the timeline from the
     * opening balance. Every intermediate level must stay within 0..capacity.
     * Returns the final level.
     */
    public function replay(Tank $tank): int
    {
        $level = $tank->opening_volume_kg;

        $tank->movements()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->each(function (CargoMovement $m) use (&$level, $tank) {
                $level += $m->delta();

                if ($level > $tank->capacity_kg) {
                    throw TankCapacityExceededException::for($tank->code, $tank->capacity_kg, $level);
                }
                if ($level < 0) {
                    throw InsufficientStockException::for($tank->code, $level);
                }
            });

        return $level;
    }
}
