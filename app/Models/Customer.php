<?php

namespace App\Models;

use App\Domain\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use Auditable;

    protected $fillable = ['name', 'contact_person', 'phone', 'email', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updated(function (self $c) {
            if ($c->wasChanged('name')) {
                DeliveryOrder::where('customer_id', $c->id)->update(['customer' => $c->name]);
            }
        });
    }

    public function auditLabel(): string
    {
        return "Customer {$this->name}";
    }

    public function orders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class);
    }
}
