<?php

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Cargo\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CargoMovement extends Model
{
    use Auditable;

    public function auditLabel(): string
    {
        $kind = match (true) {
            $this->reverses_movement_id !== null => "reversal of #{$this->reverses_movement_id}",
            $this->type instanceof MovementType => $this->type->isInbound() ? 'discharge' : 'delivery',
            default => 'movement',
        };

        return "Movement #{$this->getKey()} ({$kind}, ".number_format((int) $this->net_kg).' kg net)';
    }

    protected $fillable = [
        'tank_id', 'type', 'reference', 'gross_kg', 'loss_kg',
        'net_kg', 'occurred_at', 'idempotency_key',
        'vessel_discharge_id', 'delivery_order_id', 'reverses_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'gross_kg' => 'integer',
            'loss_kg' => 'integer',
            'net_kg' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /** The movement this one cancels out (set on reversal rows only). */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    /** The reversal that cancelled this movement, if any. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_movement_id');
    }

    public function correctionRequests(): HasMany
    {
        return $this->hasMany(CorrectionRequest::class);
    }

    public function isReversal(): bool
    {
        return $this->reverses_movement_id !== null;
    }

    /** Uses the withExists() alias when the list query provided it. */
    public function isReversed(): bool
    {
        return (bool) ($this->attributes['is_reversed'] ?? $this->reversal()->exists());
    }

    public function hasPendingCorrection(): bool
    {
        return (bool) ($this->attributes['has_pending_correction']
            ?? $this->correctionRequests()->where('status', 'pending')->exists());
    }

    public function vesselDischarge(): BelongsTo
    {
        return $this->belongsTo(VesselDischarge::class);
    }

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(Tank::class);
    }

    /** Signed effect on the tank: + inbound, - outbound. */
    public function delta(): int
    {
        return $this->type->isInbound() ? $this->net_kg : -$this->net_kg;
    }
}
