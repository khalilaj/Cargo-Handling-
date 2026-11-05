<?php

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Operations\Enums\DischargeStatus;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class VesselDischarge extends Model
{
    use Auditable;

    public function auditLabel(): string
    {
        return "Vessel discharge {$this->voyage_ref}";
    }

    protected $fillable = [
        'voyage_ref', 'vessel_name', 'commodity', 'bl_quantity_kg', 'status',
        'eta', 'berthed_at', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'commodity' => Commodity::class,
            'status' => DischargeStatus::class,
            'bl_quantity_kg' => 'integer',
            'eta' => 'datetime',
            'berthed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CargoMovement::class);
    }

    /** Discharges add, their reversals subtract. */
    private static function signed(string $column): Expression
    {
        return DB::raw("CASE WHEN type = 'vessel_discharge' THEN {$column} ELSE -{$column} END");
    }

    /** Uses the withSum() alias when the list query provided it, else queries. */
    public function receivedGrossKg(): int
    {
        return (int) ($this->attributes['received_gross_kg'] ?? $this->movements()->sum(self::signed('gross_kg')));
    }

    public function transitLossKg(): int
    {
        return (int) ($this->attributes['received_loss_kg'] ?? $this->movements()->sum(self::signed('loss_kg')));
    }

    public function receivedNetKg(): int
    {
        return $this->receivedGrossKg() - $this->transitLossKg();
    }

    public function remainingKg(): int
    {
        return max($this->bl_quantity_kg - $this->receivedGrossKg(), 0);
    }

    /** Bill of lading minus what the shore actually received (gross). */
    public function varianceKg(): int
    {
        return $this->bl_quantity_kg - $this->receivedGrossKg();
    }

    public function progressPercent(): float
    {
        return $this->bl_quantity_kg > 0
            ? round($this->receivedGrossKg() / $this->bl_quantity_kg * 100, 1)
            : 0.0;
    }

    public function scopeWithTotals($query)
    {
        return $query->withSum('movements as received_gross_kg', self::signed('gross_kg'))
            ->withSum('movements as received_loss_kg', self::signed('loss_kg'));
    }
}
