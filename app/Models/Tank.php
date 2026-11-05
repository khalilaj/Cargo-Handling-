<?php

namespace App\Models;

use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[\Illuminate\Database\Eloquent\Attributes\ObservedBy(\App\Observers\TankObserver::class)]
class Tank extends Model
{
    use Auditable;

    protected $fillable = [
        'code', 'name', 'commodity', 'capacity_kg',
        'opening_volume_kg', 'current_volume_kg', 'requires_heating',
    ];

    protected function casts(): array
    {
        return [
            'commodity' => Commodity::class,
            'capacity_kg' => 'integer',
            'opening_volume_kg' => 'integer',
            'current_volume_kg' => 'integer',
            'requires_heating' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return "Tank {$this->code}";
    }

    /** The balance is derived from the ledger; the movement itself is what gets audited. */
    protected function auditExclude(): array
    {
        return ['current_volume_kg'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CargoMovement::class);
    }

    public function fillPercent(): float
    {
        return $this->capacity_kg > 0
            ? round($this->current_volume_kg / $this->capacity_kg * 100, 1)
            : 0.0;
    }
}
