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

    /** What the single forward button says for this record, or null when nothing more can be done. */
    public static function continueLabel(Model $record): ?string
    {
        return match (true) {
            $record instanceof VesselDischarge => match ($record->status) {
                DischargeStatus::Scheduled => 'berth the ship',
                DischargeStatus::Berthed => 'start the discharge',
                DischargeStatus::Discharging => 'pump or complete',
                default => null,
            },
            $record instanceof DeliveryOrder => match ($record->status) {
                OrderStatus::Pending => 'open loading',
                OrderStatus::Loading => 'load trucks or complete',
                default => null,
            },
            $record instanceof TruckVisit => match ($record->status) {
                VisitStatus::Arrived => 'weigh the truck in',
                VisitStatus::WeighedIn => 'start loading',
                VisitStatus::Loading => 'load or weigh out',
                VisitStatus::WeighedOut => 'gate the truck out',
                default => null,
            },
            default => null,
        };
    }

    private static function discharge(VesselDischarge $d): array
    {
        $hint = match ($d->status) {
            DischargeStatus::Scheduled => 'Next: when the ship arrives, press "Mark berthed" and choose a free berth.',
            DischargeStatus::Berthed => 'Next: press "Start discharge" when pumping begins.',
            DischargeStatus::Discharging => $d->isFullyDischarged()
                ? 'Next: the cargo is in. Press "Finish pumping" and then "Complete voyage".'
                : 'Next: press "Pump into tank" for each tank you fill. The 0.5% transit loss is taken off automatically. When the whole bill of lading is in, press "Finish pumping" and then "Complete voyage".',
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
            OrderStatus::Pending => 'Next: press "Start loading" to open the order for trucks.',
            OrderStatus::Loading => $o->remainingKg() > 0
                ? "Next: gate a truck in, weigh it, load it from a tank, weigh it out and gate it out. Repeat for each truck. {$kg} kg still to load. Then finish the order."
                : 'Next: the full quantity is loaded. Once every truck has left, finish the order and press "Complete order".',
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
