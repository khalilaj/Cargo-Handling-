<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Cargo\Enums\MovementType;
use App\Domain\Cargo\Services\TankLedger;
use App\Domain\Operations\Exceptions\BillOfLadingExceededException;
use App\Domain\Operations\Exceptions\OperationNotActiveException;
use App\Models\CargoMovement;
use App\Models\VesselDischarge;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Pump part of a vessel's cargo into a shore tank.
 * Lock order is always operation -> tank, so concurrent flows cannot deadlock.
 */
class RecordDischargeIntoTank
{
    public function __construct(private TankLedger $ledger) {}

    public function __invoke(
        VesselDischarge $discharge, int $tankId, int $grossKg,
        CarbonInterface $occurredAt, string $idempotencyKey,
    ): CargoMovement {
        return DB::transaction(function () use ($discharge, $tankId, $grossKg, $occurredAt, $idempotencyKey) {
            $d = VesselDischarge::query()->lockForUpdate()->findOrFail($discharge->id);

            // A retry of an already-applied request must not be counted twice.
            if ($existing = CargoMovement::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }

            if (! $d->status->isActive()) {
                throw new OperationNotActiveException(
                    "{$d->voyage_ref} is {$d->status->label()}. Start the discharge before pumping into a tank."
                );
            }

            $remaining = $d->bl_quantity_kg - $d->receivedGrossKg();
            if ($grossKg > $remaining) {
                throw new BillOfLadingExceededException(sprintf(
                    '%s has only %s kg left on its bill of lading; %s kg requested.',
                    $d->voyage_ref, number_format($remaining), number_format($grossKg)
                ));
            }

            return $this->ledger->record(
                $tankId, MovementType::VesselDischarge, $grossKg, $occurredAt, $idempotencyKey,
                $d->vessel_name, $d->commodity, ['vessel_discharge_id' => $d->id],
            );
        });
    }
}
