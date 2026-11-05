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
    public function __invoke(DeliveryOrder $order, OrderStatus $to): DeliveryOrder
    {
        return DB::transaction(function () use ($order, $to) {
            $o = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! $o->status->canTransitionTo($to)) {
                throw new InvalidStatusTransitionException(
                    "Cannot move {$o->order_ref} from {$o->status->label()} to {$to->label()}."
                );
            }

            $hasLoads = $o->movements()->exists();

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
