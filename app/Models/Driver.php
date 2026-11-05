<?php

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Fleet\Services\ComplianceChecker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    use Auditable;

    protected $fillable = ['name', 'licence_number', 'licence_expiry', 'phone', 'transporter_id', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'licence_expiry' => 'date'];
    }

    public function auditLabel(): string
    {
        return "Driver {$this->name}";
    }

    public function transporter(): BelongsTo
    {
        return $this->belongsTo(Transporter::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(TruckVisit::class);
    }

    public function complianceStatus()
    {
        return ComplianceChecker::worst([$this->licence_expiry]);
    }
}
