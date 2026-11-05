<?php

namespace App\Models;

use App\Domain\Cargo\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CargoMovement extends Model
{
    protected $fillable = [
        'tank_id', 'type', 'reference', 'gross_kg', 'loss_kg',
        'net_kg', 'occurred_at', 'idempotency_key',
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
