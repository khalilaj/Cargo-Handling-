<?php

namespace App\Domain\Corrections\Actions;

use App\Domain\Cargo\Services\TankLedger;
use App\Domain\Corrections\Enums\CorrectionStatus;
use App\Domain\Corrections\Exceptions\CorrectionAlreadyDecidedException;
use App\Domain\Corrections\Exceptions\NotAuthorizedException;
use App\Domain\Corrections\Exceptions\SelfApprovalException;
use App\Models\CargoMovement;
use App\Models\CorrectionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A supervisor approves: the system appends the reversal movement.
 *  - role check and four-eyes rule are enforced here, not just in the UI
 *  - the request row is locked, so two approvers cannot both apply it
 *  - if the tank cannot legally take the reversal, everything rolls back and the request stays pending
 */
class ApproveCorrection
{
    public function __construct(private TankLedger $ledger)
    {
    }

    public function __invoke(CorrectionRequest $request, User $by, ?string $note = null): CorrectionRequest
    {
        if (! $by->role->canApprove()) {
            throw new NotAuthorizedException("{$by->role->label()}s cannot approve corrections.");
        }

        return DB::transaction(function () use ($request, $by, $note) {
            $r = CorrectionRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($r->status !== CorrectionStatus::Pending) {
                throw new CorrectionAlreadyDecidedException("Request #{$r->id} was already {$r->status->value}.");
            }
            if ($r->requested_by === $by->id) {
                throw new SelfApprovalException('Four-eyes rule: you cannot approve your own correction request.');
            }

            $original = CargoMovement::findOrFail($r->cargo_movement_id);
            $at = now()->max($original->occurred_at->addSecond());       // never before the thing it cancels

            $reversal = $this->ledger->reverse($original, $at, 'reversal-' . $original->id);

            $r->update([
                'status' => CorrectionStatus::Approved,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note ? trim($note) : null,
                'reversal_movement_id' => $reversal->id,
            ]);

            return $r;
        });
    }
}
