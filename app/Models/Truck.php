<?php

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Fleet\Services\ComplianceChecker;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Truck extends Model
{
    use Auditable;

    protected $fillable = ['plate', 'transporter_id', 'capacity_kg', 'tare_kg', 'insurance_expiry', 'inspection_expiry', 'notes', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'capacity_kg' => 'integer',
            'tare_kg' => 'integer',
            'insurance_expiry' => 'date',
            'inspection_expiry' => 'date',
        ];
    }

    /** "kda  214x" and "KDA 214X" are the same truck. */
    protected function plate(): Attribute
    {
        return Attribute::set(fn (string $v) => strtoupper(trim((string) preg_replace('/\s+/', ' ', $v))));
    }

    public function auditLabel(): string
    {
        return "Truck {$this->plate}";
    }

    public function transporter(): BelongsTo
    {
        return $this->belongsTo(Transporter::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(TruckVisit::class);
    }

    /** The open visit, if the truck is on site right now. */
    public function activeVisit(): HasOne
    {
        return $this->hasOne(TruckVisit::class)->whereNotNull('active_truck_id');
    }

    public function complianceStatus()
    {
        return ComplianceChecker::worst([$this->insurance_expiry, $this->inspection_expiry]);
    }
}
