<?php

namespace App\Domain\Operations\Services;

use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\VesselDischarge;
use Illuminate\Support\Collection;

/**
 * Pure read-side rules that turn the current state of the terminal into
 * a ranked list of things an operator should look at.
 *
 * @phpstan-type Alert array{level: 'danger'|'warning'|'info', title: string, detail: string}
 */
class OperationalAlerts
{
    public const NEAR_FULL_PERCENT = 90.0;
    public const LOW_STOCK_PERCENT = 10.0;

    /** @return Collection<int, array{level: string, title: string, detail: string}> */
    public function all(int $limit = 10): Collection
    {
        return collect([
            ...$this->tankAlerts(),
            ...$this->vesselAlerts(),
            ...$this->orderAlerts(),
        ])
            ->sortBy(fn ($a) => ['danger' => 0, 'warning' => 1, 'info' => 2][$a['level']])
            ->values()
            ->take($limit);
    }

    private function tankAlerts(): array
    {
        $out = [];
        foreach (Tank::orderBy('code')->get() as $t) {
            $pct = $t->fillPercent();
            $free = number_format(($t->capacity_kg - $t->current_volume_kg) / 1000, 1);

            if ($pct >= 100) {
                $out[] = ['level' => 'danger', 'title' => "{$t->code} is full", 'detail' => 'No free capacity: new discharges will be rejected.'];
            } elseif ($pct >= self::NEAR_FULL_PERCENT) {
                $out[] = ['level' => 'warning', 'title' => "{$t->code} nearly full ({$pct}%)", 'detail' => "{$free} t of free space left."];
            } elseif ($t->current_volume_kg > 0 && $pct <= self::LOW_STOCK_PERCENT) {
                $out[] = ['level' => 'warning', 'title' => "{$t->code} low stock ({$pct}%)", 'detail' => 'Check upcoming deliveries against what is in the tank.'];
            }
        }

        return $out;
    }

    private function vesselAlerts(): array
    {
        $out = [];
        $open = VesselDischarge::withTotals()->whereNotIn('status', [DischargeStatus::Completed->value, DischargeStatus::Cancelled->value])->get();

        foreach ($open as $d) {
            if ($d->status === DischargeStatus::Scheduled && $d->eta && $d->eta->isPast()) {
                $out[] = ['level' => 'warning', 'title' => "{$d->vessel_name}: ETA passed", 'detail' => "{$d->voyage_ref} is still scheduled; ETA was {$d->eta->format('d M H:i')}."];
            } elseif ($d->status === DischargeStatus::Discharging) {
                $left = number_format($d->remainingKg() / 1000, 1);
                $long = $d->started_at && $d->started_at->lt(now()->subHours(48));
                $out[] = [
                    'level' => $long ? 'warning' : 'info',
                    'title' => "{$d->vessel_name} discharging" . ($long ? ' for over 48 h' : ''),
                    'detail' => "{$d->voyage_ref}: {$left} t left on the bill of lading ({$d->progressPercent()}% received).",
                ];
            }
        }

        return $out;
    }

    private function orderAlerts(): array
    {
        $out = [];
        $open = DeliveryOrder::withTotals()->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])->get();

        foreach ($open as $o) {
            if ($o->status === OrderStatus::Pending && $o->scheduled_at && $o->scheduled_at->isPast()) {
                $out[] = ['level' => 'warning', 'title' => "{$o->order_ref} overdue", 'detail' => "{$o->customer}: scheduled for {$o->scheduled_at->format('d M H:i')} but not started."];
            } elseif ($o->status === OrderStatus::Loading) {
                $left = number_format($o->remainingKg() / 1000, 1);
                $out[] = ['level' => 'info', 'title' => "{$o->order_ref} loading", 'detail' => "{$o->customer}: {$left} t still to load ({$o->progressPercent()}% delivered)."];
            }
        }

        return $out;
    }
}
