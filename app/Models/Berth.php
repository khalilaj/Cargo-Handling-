<?php

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Operations\Enums\DischargeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Berth extends Model
{
    use Auditable;

    protected $fillable = ['code', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return "Berth {$this->code}";
    }

    public function voyages(): HasMany
    {
        return $this->hasMany(VesselDischarge::class);
    }

    /** The voyage occupying this berth right now, if any. */
    public function occupant(): ?VesselDischarge
    {
        return $this->voyages()
            ->whereIn('status', [DischargeStatus::Berthed->value, DischargeStatus::Discharging->value])
            ->latest('id')->first();
    }
}
