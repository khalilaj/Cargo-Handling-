<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Enums\OrderStatus;
use App\Domain\Operations\Exceptions\InvalidStatusTransitionException;
use App\Domain\Operations\Exceptions\OperationIncompleteException;
use App\Models\DeliveryOrder;
use Illuminate\Support\Facades\DB;

/** Moves a delivery order along its lifecycle; every transition is validated. */
class AdvanceOrderStatus
{
    /** @param  bool  $mayCloseShort  whether the actor may complete an order with less loaded than was ordered (supervisor/admin) */
    public function __invoke(DeliveryOrder $order, OrderStatus $to, bool $mayCloseShort = false): DeliveryOrder
    {
        return DB::transaction(function () use ($order, $to, $mayCloseShort) {
            $o = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! $o->status->canTransitionTo($to)) {
                throw new InvalidStatusTransitionException(
                    "Cannot move {$o->order_ref} from {$o->status->label()} to {$to->label()}."
                );
            }

            if (in_array($to, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
                $onSite = $o->visits()->whereNotNull('active_truck_id')->with('truck')->get();
                if ($onSite->isNotEmpty()) {
                    throw new OperationIncompleteException(
                        "{$o->order_ref} still has trucks on site: ".$onSite->map(fn ($v) => "{$v->truck->plate} ({$v->ref})")->join(', ').'. Gate them out or cancel their visits first.'
                    );
                }
            }

            $hasLoads = $o->deliveredKg() > 0;   // net of any reversed movements

            if ($to === OrderStatus::Completed && ! $hasLoads) {
                throw new OperationIncompleteException(
                    "{$o->order_ref} has loaded nothing yet, so it cannot be completed."
                );
            }
            if ($to === OrderStatus::Cancelled && $hasLoads) {
                throw new OperationIncompleteException(
                    "{$o->order_ref} has already loaded cargo. Complete it instead of cancelling."
                );
            }

            if ($to === OrderStatus::Completed && ! $mayCloseShort && $o->remainingKg() > 0) {
                throw new OperationIncompleteException(
                    'Only a supervisor or an admin can close an order before everything ordered is loaded.'
                );
            }

            $o->status = $to;
            match ($to) {
                OrderStatus::Loading => $o->started_at = now(),
                OrderStatus::Completed, OrderStatus::Cancelled => $o->completed_at = now(),
                default => null,
            };
            $o->save();

            return $o;
        });
    }
}
