<?php

namespace App\Domain\Operations\Services;

use App\Domain\Access\Role;
use App\Domain\Corrections\Enums\CorrectionStatus;
use App\Domain\Fleet\Enums\DocumentStatus;
use App\Domain\Fleet\Services\ComplianceChecker;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Models\CorrectionRequest;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\TruckVisit;
use App\Models\VesselDischarge;
use Illuminate\Support\Collection;

/**
 * Things a person should act on, ranked, and only the ones that person can act on.
 * Normal progress (a ship discharging, an order loading) is not an alert: the dashboard boards show it.
 *
 * Each alert carries a `target` so the UI can link to the right screen without this class knowing about the UI.
 *
 * @phpstan-type Alert array{level: 'danger'|'warning'|'info', title: string, detail: string, target: array{type: string, id?: int}}
 */
class OperationalAlerts
{
    public const NEAR_FULL_PERCENT = 90.0;

    public const LOW_STOCK_PERCENT = 10.0;

    public const TRUCK_OVERSTAY_HOURS = 12;

    /** @return Collection<int, array{level: string, title: string, detail: string, target: array<string, mixed>}> */
    public function forRole(Role $role, int $limit = 12): Collection
    {
        if (! $role->canSeeAlerts()) {
            return collect();
        }

        $alerts = [
            ...$this->tankAlerts(),
            ...$this->vesselAlerts(),
            ...$this->orderAlerts(),
            ...$this->truckAlerts(),
            ...($role->canApprove() ? $this->correctionAlerts() : []),
            ...($role->canViewRegisters() ? $this->documentAlerts() : []),
        ];

        return collect($alerts)
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
            $target = ['type' => 'tank', 'id' => $t->id];

            if ($pct >= 100) {
                $out[] = ['level' => 'danger', 'title' => "{$t->code} is full", 'detail' => 'No free capacity: new discharges will be rejected.', 'target' => $target];
            } elseif ($pct >= self::NEAR_FULL_PERCENT) {
                $out[] = ['level' => 'warning', 'title' => "{$t->code} nearly full ({$pct}%)", 'detail' => "{$free} t of free space left.", 'target' => $target];
            } elseif ($t->current_volume_kg > 0 && $pct <= self::LOW_STOCK_PERCENT) {
                $out[] = ['level' => 'warning', 'title' => "{$t->code} low stock ({$pct}%)", 'detail' => 'Check upcoming deliveries against what is in the tank.', 'target' => $target];
            }
        }

        return $out;
    }

    private function vesselAlerts(): array
    {
        $out = [];
        $open = VesselDischarge::withTotals()->whereNotIn('status', [DischargeStatus::Completed->value, DischargeStatus::Cancelled->value])->get();

        foreach ($open as $d) {
            $target = ['type' => 'voyage', 'id' => $d->id];

            if ($d->status === DischargeStatus::Scheduled && $d->eta && $d->eta->isPast()) {
                $out[] = ['level' => 'warning', 'title' => "{$d->vessel_name}: ETA passed", 'detail' => "{$d->voyage_ref} is still scheduled; ETA was {KenyaTime::of($d->eta)->format('d M H:i')}.", 'target' => $target];
            } elseif ($d->status === DischargeStatus::Discharging && $d->started_at && $d->started_at->lt(now()->subHours(48))) {
                $left = number_format($d->remainingKg() / 1000, 1);
                $out[] = ['level' => 'warning', 'title' => "{$d->vessel_name} discharging for over 48 h", 'detail' => "{$d->voyage_ref}: {$left} t left on the bill of lading ({$d->progressPercent()}% received).", 'target' => $target];
            }
        }

        return $out;
    }

    private function orderAlerts(): array
    {
        $out = [];
        $overdue = DeliveryOrder::where('status', OrderStatus::Pending->value)->whereNotNull('scheduled_at')->where('scheduled_at', '<', now())->get();

        foreach ($overdue as $o) {
            $out[] = ['level' => 'warning', 'title' => "{$o->order_ref} overdue", 'detail' => "{$o->customer}: scheduled for {KenyaTime::of($o->scheduled_at)->format('d M H:i')} but not started.", 'target' => ['type' => 'order', 'id' => $o->id]];
        }

        return $out;
    }

    /** Trucks on site with document or weighbridge warnings, and trucks that have been here too long. */
    private function truckAlerts(): array
    {
        $out = [];

        foreach (TruckVisit::whereNotNull('active_truck_id')->with('truck')->orderBy('gate_in_at')->get() as $v) {
            $target = ['type' => 'visit', 'id' => $v->id];
            $warnings = $v->warnings ?? [];

            if ($warnings) {
                $more = count($warnings) > 2 ? ' (+'.(count($warnings) - 2).' more)' : '';
                $out[] = ['level' => 'warning', 'title' => "{$v->truck->plate} on site with warnings", 'detail' => implode('; ', array_slice($warnings, 0, 2)).$more, 'target' => $target];
            }
            if ($v->gate_in_at->lt(now()->subHours(self::TRUCK_OVERSTAY_HOURS))) {
                $out[] = ['level' => 'warning', 'title' => "{$v->truck->plate} on site for over ".self::TRUCK_OVERSTAY_HOURS.' h', 'detail' => "{$v->ref} ({$v->status->label()}) arrived ".$v->gate_in_at->diffForHumans().'.', 'target' => $target];
            }
        }

        return $out;
    }

    private function correctionAlerts(): array
    {
        $n = CorrectionRequest::where('status', CorrectionStatus::Pending->value)->count();

        return $n === 0 ? [] : [[
            'level' => 'warning',
            'title' => "{$n} correction request".($n === 1 ? '' : 's').' awaiting approval',
            'detail' => 'Approve or reject them (Operations → Corrections).',
            'target' => ['type' => 'corrections'],
        ]];
    }

    /** One summary line: how many active trucks / drivers have documents to chase. */
    private function documentAlerts(): array
    {
        $needAttention = fn ($expiry) => in_array(ComplianceChecker::statusOf($expiry), [DocumentStatus::Expired, DocumentStatus::ExpiringSoon], true);

        $trucks = Truck::where('active', true)->get()->filter(fn (Truck $t) => $needAttention($t->insurance_expiry) || $needAttention($t->inspection_expiry))->count();
        $drivers = Driver::where('active', true)->get()->filter(fn (Driver $d) => $needAttention($d->licence_expiry))->count();

        return $trucks + $drivers === 0 ? [] : [[
            'level' => 'info',
            'title' => 'Documents to chase: '.implode(' and ', array_filter([$trucks ? "{$trucks} truck".($trucks === 1 ? '' : 's') : null, $drivers ? "{$drivers} driver".($drivers === 1 ? '' : 's') : null])),
            'detail' => 'Expired or expiring within '.DocumentStatus::SOON_DAYS.' days (Registers → Trucks / Drivers). They warn at the gate but never block.',
            'target' => ['type' => 'trucks'],
        ]];
    }
}
