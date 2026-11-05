<?php

namespace App\Domain\Cargo\Services;

use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Cargo\Enums\MovementType;
use App\Domain\Cargo\Exceptions\CommodityMismatchException;
use App\Domain\Cargo\Exceptions\InsufficientStockException;
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
