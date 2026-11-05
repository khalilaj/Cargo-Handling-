<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Exceptions\InvalidStatusTransitionException;
use App\Domain\Operations\Exceptions\OperationIncompleteException;
use App\Models\VesselDischarge;
use Illuminate\Support\Facades\DB;

/** Moves a vessel discharge along its lifecycle; every transition is validated. */
class AdvanceDischargeStatus
{
    public function __invoke(VesselDischarge $discharge, DischargeStatus $to): VesselDischarge
    {
        return DB::transaction(function () use ($discharge, $to) {
            $d = VesselDischarge::query()->lockForUpdate()->findOrFail($discharge->id);

            if (! $d->status->canTransitionTo($to)) {
                throw new InvalidStatusTransitionException(
                    "Cannot move {$d->voyage_ref} from {$d->status->label()} to {$to->label()}."
                );
            }

            $hasCargo = $d->receivedGrossKg() > 0;   // net of any reversed movements

            if ($to === DischargeStatus::Completed && ! $hasCargo) {
                throw new OperationIncompleteException(
                    "{$d->voyage_ref} has received no cargo yet, so it cannot be completed."
                );
            }
            if ($to === DischargeStatus::Cancelled && $hasCargo) {
                throw new OperationIncompleteException(
                    "{$d->voyage_ref} has already put cargo into tanks. Complete it instead of cancelling."
                );
            }

            $d->status = $to;
            match ($to) {
                DischargeStatus::Berthed => $d->berthed_at = now(),
                DischargeStatus::Discharging => $d->started_at = now(),
                DischargeStatus::Completed, DischargeStatus::Cancelled => $d->completed_at = now(),
                default => null,
            };
            $d->save();

            return $d;
        });
    }
}
