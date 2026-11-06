<?php

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Operations\Enums\OrderStatus;
use App\Domain\Operations\Services\CodeGenerator;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class DeliveryOrder extends Model
{
    use Auditable;

    protected static function booted(): void
    {
        static::creating(function (self $o) {
            $o->order_ref = filled($o->order_ref) ? $o->order_ref : app(CodeGenerator::class)->order();
        });

        static::saving(function (self $o) {
            if ($o->customer_id && ($o->isDirty('customer_id') || blank($o->customer))) {
                $o->customer = Customer::find($o->customer_id)?->name ?? $o->customer;
            }
        });
    }

    public function customerRecord(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(TruckVisit::class);
    }

    public function auditLabel(): string
    {
        return "Delivery order {$this->order_ref}";
    }

    protected $fillable = [
        'order_ref', 'customer_id', 'customer', 'commodity', 'ordered_kg', 'status',
        'scheduled_at', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'commodity' => Commodity::class,
            'status' => OrderStatus::class,
            'ordered_kg' => 'integer',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CargoMovement::class);
    }

    /** Deliveries add, their reversals subtract. */
    private static function signed(string $column): Expression
    {
        return DB::raw("CASE WHEN type = 'truck_delivery' THEN {$column} ELSE -{$column} END");
    }

    public function deliveredKg(): int
    {
        return (int) ($this->attributes['delivered_kg'] ?? $this->movements()->sum(self::signed('net_kg')));
    }

    public function remainingKg(): int
    {
        return max($this->ordered_kg - $this->deliveredKg(), 0);
    }

    public function progressPercent(): float
    {
        return $this->ordered_kg > 0
            ? round($this->deliveredKg() / $this->ordered_kg * 100, 1)
            : 0.0;
    }

    public function scopeWithTotals($query)
    {
        return $query->withSum('movements as delivered_kg', self::signed('net_kg'));
    }
}
