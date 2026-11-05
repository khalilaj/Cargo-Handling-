<?php

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Operations\Enums\DischargeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vessel extends Model
{
    use Auditable;

    protected $fillable = ['name', 'imo', 'flag', 'owner_agent', 'capacity_kg', 'notes', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'capacity_kg' => 'integer'];
    }

    protected static function booted(): void
    {
        // Voyages keep a copy of the name for fast listing; keep the copy in step with the register.
        static::updated(function (self $v) {
            if ($v->wasChanged('name')) {
                VesselDischarge::where('vessel_id', $v->id)->update(['vessel_name' => $v->name]);
            }
        });
    }

    public function auditLabel(): string
    {
        return "Vessel {$this->name}";
    }

    public function voyages(): HasMany
    {
        return $this->hasMany(VesselDischarge::class);
    }

    /** The voyage that currently has this ship alongside (berthed or discharging), if any. */
    public function currentVoyage(): ?VesselDischarge
    {
        return $this->voyages()
            ->whereIn('status', [DischargeStatus::Berthed->value, DischargeStatus::Discharging->value])
            ->with('berth')->latest('id')->first();
    }
}
