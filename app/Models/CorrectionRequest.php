<?php

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Corrections\Enums\CorrectionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorrectionRequest extends Model
{
    use Auditable;

    protected $fillable = [
        'cargo_movement_id', 'requested_by', 'reason', 'status',
        'decided_by', 'decided_at', 'decision_note', 'reversal_movement_id',
    ];

    protected function casts(): array
    {
        return ['status' => CorrectionStatus::class, 'decided_at' => 'datetime'];
    }

    public function auditLabel(): string
    {
        return "Correction request #{$this->getKey()} for movement #{$this->cargo_movement_id}";
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(CargoMovement::class, 'cargo_movement_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(CargoMovement::class, 'reversal_movement_id');
    }
}
