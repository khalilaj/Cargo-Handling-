<?php

namespace App\Models;

use App\Domain\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transporter extends Model
{
    use Auditable;

    protected $fillable = ['name', 'phone', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return "Transporter {$this->name}";
    }

    public function trucks(): HasMany
    {
        return $this->hasMany(Truck::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }
}
