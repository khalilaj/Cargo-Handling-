<?php

namespace App\Domain\Corrections\Actions;

use App\Domain\Corrections\Enums\CorrectionStatus;
use App\Domain\Corrections\Exceptions\CorrectionAlreadyDecidedException;
use App\Domain\Corrections\Exceptions\InvalidCorrectionException;
use App\Domain\Corrections\Exceptions\NotAuthorizedException;
use App\Models\CorrectionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RejectCorrection
{
    public function __invoke(CorrectionRequest $request, User $by, string $note): CorrectionRequest
    {
        if (! $by->role->canApprove()) {
            throw new NotAuthorizedException("{$by->role->label()}s cannot reject corrections.");
        }

        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw new InvalidCorrectionException('Please say why the request is rejected.');
        }

        return DB::transaction(function () use ($request, $by, $note) {
            $r = CorrectionRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($r->status !== CorrectionStatus::Pending) {
                throw new CorrectionAlreadyDecidedException("Request #{$r->id} was already {$r->status->value}.");
            }

            $r->update([
                'status' => CorrectionStatus::Rejected,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            return $r;
        });
    }
}
