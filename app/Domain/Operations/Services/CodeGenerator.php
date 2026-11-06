<?php

namespace App\Domain\Operations\Services;

use App\Domain\Cargo\Enums\Commodity;
use App\Models\Berth;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\VesselDischarge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reference numbers and codes that nobody should have to invent or type: voyage VOY-2611-03, order ORD-1004,
 * tank CPO-03, berth B4. The next number continues from the highest one already used, so a number is never
 * reused. Numbers are handed out under a lock (see next()), and the database's UNIQUE index stays as a last guard.
 */
class CodeGenerator
{
    public function voyage(?Carbon $at = null): string
    {
        $month = ($at ?? now())->format('ym');
        $prefix = "VOY-{$month}-";

        return $prefix.str_pad((string) $this->next("voyage:{$month}", $this->highest(VesselDischarge::class, 'voyage_ref', $prefix)), 2, '0', STR_PAD_LEFT);
    }

    public function order(): string
    {
        return 'ORD-'.$this->next('order', max(1000, $this->highest(DeliveryOrder::class, 'order_ref', 'ORD-')));
    }

    public function tank(Commodity $commodity): string
    {
        $prefix = strtoupper($commodity->value).'-';

        return $prefix.str_pad((string) $this->next('tank:'.$commodity->value, $this->highest(Tank::class, 'code', $prefix)), 2, '0', STR_PAD_LEFT);
    }

    public function berth(): string
    {
        return 'B'.$this->next('berth', $this->highest(Berth::class, 'code', 'B'));
    }

    /**
     * The next free number for a counter. The counter row is locked while it is read and advanced, so two people
     * numbering at the same instant are handed different numbers; it also never falls behind the highest number
     * already stored (for example one imported with its own code).
     */
    private function next(string $counter, int $highestInUse): int
    {
        return DB::transaction(function () use ($counter, $highestInUse) {
            DB::table('code_sequences')->insertOrIgnore(['name' => $counter, 'last_value' => 0]);
            $current = (int) DB::table('code_sequences')->where('name', $counter)->lockForUpdate()->value('last_value');
            $next = max($current, $highestInUse) + 1;
            DB::table('code_sequences')->where('name', $counter)->update(['last_value' => $next]);

            return $next;
        });
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
