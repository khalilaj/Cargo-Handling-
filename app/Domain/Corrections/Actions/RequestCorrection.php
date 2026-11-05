<?php

namespace App\Domain\Corrections\Actions;

use App\Domain\Corrections\Enums\CorrectionStatus;
use App\Domain\Corrections\Exceptions\InvalidCorrectionException;
use App\Domain\Corrections\Exceptions\NotAuthorizedException;
use App\Models\CargoMovement;
use App\Models\CorrectionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** An operator asks for a mistaken movement to be reversed. Nothing changes until a supervisor approves. */
class RequestCorrection
{
    public const MIN_REASON_LENGTH = 10;

    public function __invoke(CargoMovement $movement, User $by, string $reason): CorrectionRequest
    {
        if (! $by->role->canOperate()) {
            throw new NotAuthorizedException("{$by->role->label()}s cannot request corrections.");
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new InvalidCorrectionException('Please explain the correction (at least ' . self::MIN_REASON_LENGTH . ' characters).');
        }

        return DB::transaction(function () use ($movement, $by, $reason) {
            $m = CargoMovement::query()->lockForUpdate()->findOrFail($movement->id);   // serialises concurrent requests

            if ($m->isReversal()) {
                throw new InvalidCorrectionException('A reversal cannot be corrected again.');
            }
            if ($m->reversal()->exists()) {
                throw new InvalidCorrectionException("Movement #{$m->id} has already been reversed.");
            }
            if ($m->correctionRequests()->where('status', CorrectionStatus::Pending->value)->exists()) {
                throw new InvalidCorrectionException("Movement #{$m->id} already has a correction awaiting approval.");
            }

            return CorrectionRequest::create([
                'cargo_movement_id' => $m->id,
                'requested_by' => $by->id,
                'reason' => $reason,
                'status' => CorrectionStatus::Pending,
            ]);
        });
    }
}
