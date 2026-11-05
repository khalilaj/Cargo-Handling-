<?php

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Cargo\Enums\MovementType;
use App\Domain\Fleet\Enums\VisitStatus;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class TruckVisit extends Model
{
    use Auditable;

    protected $fillable = [
        'truck_id', 'driver_id', 'delivery_order_id', 'status', 'active_truck_id',
        'gate_in_at', 'weighed_in_at', 'weigh_in_kg', 'loading_started_at',
        'weighed_out_at', 'weigh_out_kg', 'gate_out_at', 'warnings', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => VisitStatus::class,
            'warnings' => 'array',
            'weigh_in_kg' => 'integer',
            'weigh_out_kg' => 'integer',
            'gate_in_at' => 'datetime',
            'weighed_in_at' => 'datetime',
            'loading_started_at' => 'datetime',
            'weighed_out_at' => 'datetime',
            'gate_out_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return "Truck visit {$this->ref}";
    }

    /** TV-00042: derived from the id, so it can never collide or drift. */
    public function getRefAttribute(): string
    {
        return 'TV-'.str_pad((string) $this->getKey(), 5, '0', STR_PAD_LEFT);
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'delivery_order_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CargoMovement::class);
    }

    /** Loads add, their reversals subtract. */
    private static function signedNet(): Expression
    {
        return DB::raw("CASE WHEN type = '".MovementType::TruckDelivery->value."' THEN net_kg ELSE -net_kg END");
    }

    /** Net kg loaded onto this truck, according to the ledger. */
    public function loadedKg(): int
    {
        return (int) ($this->attributes['loaded_kg'] ?? $this->movements()->sum(self::signedNet()));
    }

    /** Weighbridge net: loaded weight minus empty weight. Null until both are recorded. */
    public function weighbridgeNetKg(): ?int
    {
        return $this->weigh_in_kg !== null && $this->weigh_out_kg !== null ? $this->weigh_out_kg - $this->weigh_in_kg : null;
    }

    /** Weighbridge minus ledger. Positive = the scale saw more than was booked out. */
    public function varianceKg(): ?int
    {
        $net = $this->weighbridgeNetKg();

        return $net === null ? null : $net - $this->loadedKg();
    }

    public function scopeWithTotals($query)
    {
        return $query->withSum('movements as loaded_kg', self::signedNet());
    }

    public function scopeOpen($query)
    {
        return $query->whereNotNull('active_truck_id');
    }
}
