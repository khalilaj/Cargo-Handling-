<?php

namespace App\Domain\Operations\Services;

use App\Domain\Cargo\Enums\Commodity;
use App\Models\Berth;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\VesselDischarge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Reference numbers and codes that nobody should have to invent or type: voyage VOY-2611-03, order ORD-1004,
 * tank CPO-03, berth B4. The next number continues from the highest one already used, so a number is never
 * reused. The database's UNIQUE index is the final guard if two people save at the very same moment.
 */
class CodeGenerator
{
    public function voyage(?Carbon $at = null): string
    {
        $prefix = 'VOY-'.($at ?? now())->format('ym').'-';

        return $prefix.str_pad((string) ($this->highest(VesselDischarge::class, 'voyage_ref', $prefix) + 1), 2, '0', STR_PAD_LEFT);
    }

    public function order(): string
    {
        return 'ORD-'.max(1001, $this->highest(DeliveryOrder::class, 'order_ref', 'ORD-') + 1);
    }

    public function tank(Commodity $commodity): string
    {
        $prefix = strtoupper($commodity->value).'-';

        return $prefix.str_pad((string) ($this->highest(Tank::class, 'code', $prefix) + 1), 2, '0', STR_PAD_LEFT);
    }

    public function berth(): string
    {
        return 'B'.($this->highest(Berth::class, 'code', 'B') + 1);
    }

    /** The largest number that follows $prefix in the column, or 0 when none. Codes of another shape are ignored. */
    private function highest(string $model, string $column, string $prefix): int
    {
        /** @var Model $model */
        return $model::query()->where($column, 'like', $prefix.'%')->pluck($column)
            ->map(fn (string $code) => preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $code, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;
    }
}
