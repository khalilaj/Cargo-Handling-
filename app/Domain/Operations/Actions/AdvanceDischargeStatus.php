<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Fleet\Exceptions\BerthOccupiedException;
use App\Domain\Fleet\Exceptions\BerthRequiredException;
use App\Domain\Fleet\Exceptions\RegisterInactiveException;
use App\Domain\Fleet\Exceptions\VesselBusyException;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Exceptions\InvalidStatusTransitionException;
use App\Domain\Operations\Exceptions\OperationIncompleteException;
use App\Models\Berth;
use App\Models\User;
use App\Models\VesselDischarge;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Moves a vessel discharge along its lifecycle; every transition is validated. */
class AdvanceDischargeStatus
{
    /**
     * @param  string|null  $shortfallReason  only for Completed while cargo is still on the ship: why the voyage is closed early
     * @param  User|null  $by  who is asking; only a supervisor or admin may close a voyage early
     */
    public function __invoke(VesselDischarge $discharge, DischargeStatus $to, ?Berth $berth = null, ?string $shortfallReason = null, ?User $by = null): VesselDischarge
    {
        try {
            return $this->advance($discharge, $to, $berth, $shortfallReason, $by);
        } catch (UniqueConstraintViolationException) {
            // Another operator berthed a ship here (or this ship elsewhere) in the same instant; the database picked the winner.
            throw new BerthOccupiedException('That berth, or that vessel, was just taken by another operator. Refresh and choose again.');
        }
    }

    private function advance(VesselDischarge $discharge, DischargeStatus $to, ?Berth $berth, ?string $shortfallReason, ?User $by): VesselDischarge
    {
        return DB::transaction(function () use ($discharge, $to, $berth, $shortfallReason, $by) {
            $d = VesselDischarge::query()->lockForUpdate()->findOrFail($discharge->id);

            if (! $d->status->canTransitionTo($to)) {
                throw new InvalidStatusTransitionException(
                    "Cannot move {$d->voyage_ref} from {$d->status->label()} to {$to->label()}."
                );
            }

            if ($to === DischargeStatus::Berthed) {
                $this->assertCanBerth($d, $berth);
            }

            $hasCargo = $d->receivedGrossKg() > 0;   // net of any reversed movements

            if ($to === DischargeStatus::Completed && ! $hasCargo) {
                throw new OperationIncompleteException(
                    "{$d->voyage_ref} has received no cargo yet, so it cannot be completed."
                );
            }
            if ($to === DischargeStatus::Completed && $hasCargo && ! $d->isFullyDischarged()) {
                $this->assertMayCloseEarly($d, $shortfallReason, $by);
                $d->completion_note = trim($shortfallReason);
            }
            if ($to === DischargeStatus::Cancelled && $hasCargo) {
                throw new OperationIncompleteException(
                    "{$d->voyage_ref} has already put cargo into tanks. Complete it instead of cancelling."
                );
            }

            $d->status = $to;
            if ($to === DischargeStatus::Berthed) {
                $d->berth_id = ($berth ?? $d->berth)->id;
            }
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

    /** Closing a voyage with cargo still on the ship is an exception: a supervisor or admin, with a written reason. */
    private function assertMayCloseEarly(VesselDischarge $d, ?string $reason, ?User $by): void
    {
        $pct = $d->progressPercent();
        $left = number_format($d->remainingKg());

        if (! ($by?->role->canApprove() ?? false)) {
            throw new OperationIncompleteException(
                "{$d->voyage_ref} has received only {$pct}% of its bill of lading ({$left} kg still to discharge). Keep pumping, or ask a supervisor to close it early."
            );
        }
        if (blank($reason)) {
            throw new OperationIncompleteException(
                "{$d->voyage_ref} has received only {$pct}% of its bill of lading. Give the reason it is being closed early."
            );
        }
    }

    /**
     * A ship can only come alongside a free, active berth, and a ship cannot be in two places.
     * The berth row is locked, so two operators cannot berth different ships at the same berth at once.
     */
    private function assertCanBerth(VesselDischarge $d, ?Berth $berth): void
    {
        $berth ??= $d->berth;
        if ($berth === null) {
            throw new BerthRequiredException("Choose a berth for {$d->voyage_ref} before marking it berthed.");
        }

        $berth = Berth::query()->lockForUpdate()->findOrFail($berth->id);
        if (! $berth->active) {
            throw new RegisterInactiveException("Berth {$berth->code} is not in service.");
        }

        $alongside = [DischargeStatus::Berthed->value, DischargeStatus::Discharging->value];

        $occupant = VesselDischarge::where('berth_id', $berth->id)->whereIn('status', $alongside)->where('id', '!=', $d->id)->first();
        if ($occupant) {
            throw new BerthOccupiedException("Berth {$berth->code} is occupied by {$occupant->vessel_name} ({$occupant->voyage_ref}).");
        }

        if ($d->vessel_id) {
            if (! $d->vessel->active) {
                throw new RegisterInactiveException("Vessel {$d->vessel->name} is deactivated in the register.");
            }
            $other = VesselDischarge::where('vessel_id', $d->vessel_id)->whereIn('status', $alongside)->where('id', '!=', $d->id)->first();
            if ($other) {
                throw new VesselBusyException("{$d->vessel->name} is already alongside on {$other->voyage_ref}.");
            }
        }
    }
}
