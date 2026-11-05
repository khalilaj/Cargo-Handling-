<?php

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Fleet\Exceptions\InvalidVisitException;
use App\Domain\Fleet\Exceptions\RegisterInactiveException;
use App\Domain\Fleet\Exceptions\TruckAlreadyOnSiteException;
use App\Domain\Fleet\Services\ComplianceChecker;
use App\Domain\Operations\Enums\OrderStatus;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Truck;
use App\Models\TruckVisit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Life of one truck on site:  arrived -> weighed in -> loading -> weighed out -> departed
 *                             (or cancelled before loading starts)
 *
 * Rules
 *  - one open visit per truck, and per driver (also enforced by a UNIQUE column in the database)
 *  - expired or missing documents WARN: the warning is recorded on the visit, the truck is never refused
 *  - weighbridge differences WARN; physical limits (payload) are enforced when loading, in RecordTruckLoad
 *  - cargo is only taken while the visit is "loading", and only for the visit's own order
 */
class TruckVisitWorkflow
{
    /** Weighbridge may differ from the ledger by this share (or 100 kg, whichever is larger) before it warns. */
    public const WEIGHT_TOLERANCE = 0.005;

    public const MIN_TOLERANCE_KG = 100;

    public const TARE_TOLERANCE = 0.10;

    public function __construct(private ComplianceChecker $compliance) {}

    public function gateIn(DeliveryOrder $order, Truck $truck, ?Driver $driver = null, ?string $notes = null): TruckVisit
    {
        try {
            return DB::transaction(function () use ($order, $truck, $driver, $notes) {
                $truck = Truck::query()->lockForUpdate()->findOrFail($truck->id);      // serialises gate-ins of the same truck

                if (! $truck->active) {
                    throw new RegisterInactiveException("Truck {$truck->plate} is deactivated in the register.");
                }
                $driver = $driver ? Driver::findOrFail($driver->id) : null;        // always judge the stored record, not a stale copy
                if ($driver && ! $driver->active) {
                    throw new RegisterInactiveException("Driver {$driver->name} is deactivated in the register.");
                }

                $order = DeliveryOrder::findOrFail($order->id);
                if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Loading], true)) {
                    throw new InvalidVisitException("{$order->order_ref} is {$order->status->label()}; trucks cannot be gated in for it.");
                }

                if ($open = TruckVisit::where('truck_id', $truck->id)->whereNotNull('active_truck_id')->first()) {
                    throw new TruckAlreadyOnSiteException("Truck {$truck->plate} is already on site ({$open->ref}).");
                }
                if ($driver && ($open = TruckVisit::where('driver_id', $driver->id)->whereNotNull('active_truck_id')->with('truck')->first())) {
                    throw new TruckAlreadyOnSiteException("Driver {$driver->name} is already on site with {$open->truck->plate} ({$open->ref}).");
                }

                return TruckVisit::create([
                    'truck_id' => $truck->id,
                    'driver_id' => $driver?->id,
                    'delivery_order_id' => $order->id,
                    'status' => VisitStatus::Arrived,
                    'active_truck_id' => $truck->id,
                    'gate_in_at' => now(),
                    'warnings' => $this->compliance->warningsFor($truck, $driver) ?: null,
                    'notes' => $notes,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Two gates raced for the same truck and the database's unique column picked the winner.
            throw new TruckAlreadyOnSiteException("Truck {$truck->plate} is already on site.");
        }
    }

    public function weighIn(TruckVisit $visit, int $emptyKg): TruckVisit
    {
        return $this->step($visit, VisitStatus::WeighedIn, function (TruckVisit $v) use ($emptyKg) {
            if ($emptyKg <= 0) {
                throw new InvalidVisitException('Enter the empty weight from the weighbridge.');
            }
            $v->weigh_in_kg = $emptyKg;
            $v->weighed_in_at = now();

            $tare = $v->truck->tare_kg;
            if ($tare && abs($emptyKg - $tare) / $tare > self::TARE_TOLERANCE) {
                $pct = round(abs($emptyKg - $tare) / $tare * 100);
                $this->warn($v, 'Empty weight '.number_format($emptyKg).' kg differs from the registered '.number_format($tare)." kg by {$pct}%");
            }
        });
    }

    public function startLoading(TruckVisit $visit): TruckVisit
    {
        return $this->step($visit, VisitStatus::Loading, function (TruckVisit $v) {
            $order = DeliveryOrder::findOrFail($v->delivery_order_id);
            if ($order->status !== OrderStatus::Loading) {
                throw new InvalidVisitException("{$order->order_ref} is {$order->status->label()}. Start loading the order first.");
            }
            $v->loading_started_at = now();
        });
    }

    public function weighOut(TruckVisit $visit, int $loadedKg): TruckVisit
    {
        return $this->step($visit, VisitStatus::WeighedOut, function (TruckVisit $v) use ($loadedKg) {
            $booked = $v->loadedKg();
            if ($booked <= 0) {
                throw new InvalidVisitException("{$v->ref} has no cargo booked out of a tank yet; load it before weighing out.");
            }
            if ($v->weigh_in_kg === null || $loadedKg <= $v->weigh_in_kg) {
                throw new InvalidVisitException('The loaded weight must be more than the empty weight recorded on arrival.');
            }

            $v->weigh_out_kg = $loadedKg;
            $v->weighed_out_at = now();

            $net = $loadedKg - $v->weigh_in_kg;
            $diff = $net - $booked;
            if (abs($diff) > max(self::MIN_TOLERANCE_KG, $booked * self::WEIGHT_TOLERANCE)) {
                $this->warn($v, 'Weighbridge shows '.number_format($net).' kg but '.number_format($booked).' kg was booked out of the tank ('.($diff > 0 ? '+' : '').number_format($diff).' kg)');
            }
            if ($v->truck->capacity_kg && $net > $v->truck->capacity_kg) {
                $this->warn($v, 'Weighbridge net '.number_format($net).' kg is over the truck payload of '.number_format($v->truck->capacity_kg).' kg');
            }
        });
    }

    public function gateOut(TruckVisit $visit): TruckVisit
    {
        return $this->step($visit, VisitStatus::Departed, function (TruckVisit $v) {
            $v->gate_out_at = now();
            $v->active_truck_id = null;                                // frees the truck for its next visit
        });
    }

    public function cancel(TruckVisit $visit): TruckVisit
    {
        return $this->step($visit, VisitStatus::Cancelled, function (TruckVisit $v) {
            if ($v->movements()->exists()) {
                throw new InvalidVisitException("{$v->ref} has cargo booked against it and cannot be cancelled.");
            }
            $v->gate_out_at = now();
            $v->active_truck_id = null;
        });
    }

    /** Lock the visit, check the transition is legal, apply the change, save. */
    private function step(TruckVisit $visit, VisitStatus $to, callable $apply): TruckVisit
    {
        return DB::transaction(function () use ($visit, $to, $apply) {
            $v = TruckVisit::query()->lockForUpdate()->with('truck')->findOrFail($visit->id);

            if (! $v->status->canTransitionTo($to)) {
                throw new InvalidVisitException("Cannot move {$v->ref} from {$v->status->label()} to {$to->label()}.");
            }

            $apply($v);
            $v->status = $to;
            $v->save();

            return $v;
        });
    }

    private function warn(TruckVisit $v, string $message): void
    {
        $v->warnings = array_values(array_unique([...($v->warnings ?? []), $message]));
    }
}
