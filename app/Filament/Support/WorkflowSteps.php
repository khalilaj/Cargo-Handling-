<?php

namespace App\Filament\Support;

use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Models\DeliveryOrder;
use App\Models\TruckVisit;
use App\Models\VesselDischarge;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Plain-language progress for the three workflows (voyage, delivery order, truck visit):
 * the steps with done / current / to-do state, and one sentence saying what to do next.
 * Read-only: every rule still lives in the domain Actions.
 */
class WorkflowSteps
{
    /** @return array{steps: list<array{label: string, state: string}>, hint: string, cancelled: bool} */
    public static function for(Model $record): array
    {
        return match (true) {
            $record instanceof VesselDischarge => self::discharge($record),
            $record instanceof DeliveryOrder => self::order($record),
            $record instanceof TruckVisit => self::visit($record),
            default => ['steps' => [], 'hint' => '', 'cancelled' => false],
        };
    }

    private static function discharge(VesselDischarge $d): array
    {
        $kg = number_format($d->remainingKg());
        $hint = match ($d->status) {
            DischargeStatus::Scheduled => 'Next: when the ship arrives, press "Mark berthed" and choose a free berth.',
            DischargeStatus::Berthed => 'Next: press "Start discharge" when pumping begins.',
            DischargeStatus::Discharging => $d->isFullyDischarged()
                ? 'Next: the cargo is in. Press "Complete" to close the voyage.'
                : "Next: press \"Pump into tank\" for each tank you fill. The 0.5% transit loss is taken off automatically. {$d->progressPercent()}% of the bill of lading is received, {$kg} kg still on the ship. Press \"Complete\" once at least 99% is in. Below that, only a supervisor can close it, with a written reason.",
            DischargeStatus::Completed => 'Done. Everything pumped is in the tanks, and the transit loss is recorded below.',
            DischargeStatus::Cancelled => 'This voyage was cancelled. Nothing more can be done on it.',
        };

        return self::build(
            [DischargeStatus::Scheduled, DischargeStatus::Berthed, DischargeStatus::Discharging, DischargeStatus::Completed],
            $d->status, DischargeStatus::Cancelled, $hint,
            fn (DischargeStatus $s) => $s->label(),
        );
    }

    private static function order(DeliveryOrder $o): array
    {
        $kg = number_format($o->remainingKg());
        $hint = match ($o->status) {
            OrderStatus::Pending => 'Next: press "Start loading" when trucks can begin. Gate each truck in under Operations, Truck visits.',
            OrderStatus::Loading => $o->remainingKg() > 0
                ? "Next: for each truck in the loading bay, press \"Load truck\" and choose a tank. {$kg} kg still to load. Press \"Complete\" when the order is delivered."
                : 'Next: the full quantity is loaded. Press "Complete" to close the order.',
            OrderStatus::Completed => 'Done. The order is fully delivered and closed.',
            OrderStatus::Cancelled => 'This order was cancelled. Nothing more can be done on it.',
        };

        return self::build(
            [OrderStatus::Pending, OrderStatus::Loading, OrderStatus::Completed],
            $o->status, OrderStatus::Cancelled, $hint,
            fn (OrderStatus $s) => $s->label(),
        );
    }

    private static function visit(TruckVisit $v): array
    {
        $hint = match ($v->status) {
            VisitStatus::Arrived => 'Next: weigh the empty truck, then press "Weigh in".',
            VisitStatus::WeighedIn => 'Next: press "Start loading" once the truck is in the loading bay. The delivery order must already be in Loading.',
            VisitStatus::Loading => 'Next: press "Load from tank" for each tank the oil comes from, then press "Weigh out".',
            VisitStatus::WeighedOut => 'Next: press "Gate out" to release the truck.',
            VisitStatus::Departed => 'Done. The truck has left the terminal.',
            VisitStatus::Cancelled => 'This visit was cancelled. The truck did not load.',
        };

        return self::build(
            [VisitStatus::Arrived, VisitStatus::WeighedIn, VisitStatus::Loading, VisitStatus::WeighedOut, VisitStatus::Departed],
            $v->status, VisitStatus::Cancelled, $hint,
            fn (VisitStatus $s) => $s->label(),
        );
    }

    /**
     * @param  list<BackedEnum>  $flow  the steps in order
     * @return array{steps: list<array{label: string, state: string}>, hint: string, cancelled: bool}
     */
    private static function build(array $flow, BackedEnum $current, BackedEnum $cancelled, string $hint, callable $label): array
    {
        $isCancelled = $current === $cancelled;
        $at = array_search($current, $flow, true);
        $last = count($flow) - 1;

        $steps = [];
        foreach ($flow as $i => $step) {
            $state = match (true) {
                $isCancelled => 'todo',
                $i < $at => 'done',
                $i === $at && $i === $last => 'done',
                $i === $at => 'current',
                default => 'todo',
            };
            $steps[] = ['label' => $label($step), 'state' => $state];
        }

        return ['steps' => $steps, 'hint' => $hint, 'cancelled' => $isCancelled];
    }
}
