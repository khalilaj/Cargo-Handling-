<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Cargo\Enums\MovementType;
use App\Domain\Cargo\Services\TankLedger;
use App\Domain\Operations\Exceptions\OperationNotActiveException;
use App\Domain\Operations\Exceptions\OrderQuantityExceededException;
use App\Models\CargoMovement;
use App\Models\DeliveryOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Load part of a delivery order from a tank onto a truck. Lock order: order -> tank. */
class RecordTruckLoad
{
    public function __construct(private TankLedger $ledger)
    {
    }

    public function __invoke(
        DeliveryOrder $order, int $tankId, int $kg, CarbonInterface $occurredAt,
        string $idempotencyKey, ?string $truck = null,
    ): CargoMovement {
        return DB::transaction(function () use ($order, $tankId, $kg, $occurredAt, $idempotencyKey, $truck) {
            $o = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($existing = CargoMovement::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }

            if (! $o->status->isActive()) {
                throw new OperationNotActiveException(
                    "{$o->order_ref} is {$o->status->label()}. Start loading before taking cargo from a tank."
                );
            }

            $remaining = $o->ordered_kg - $o->deliveredKg();
            if ($kg > $remaining) {
                throw new OrderQuantityExceededException(sprintf(
                    '%s has only %s kg left to deliver; %s kg requested.',
                    $o->order_ref, number_format($remaining), number_format($kg)
                ));
            }

            return $this->ledger->record(
                $tankId, MovementType::TruckDelivery, $kg, $occurredAt, $idempotencyKey,
                trim($o->order_ref . ($truck ? " · {$truck}" : '')), $o->commodity,
                ['delivery_order_id' => $o->id],
            );
        });
    }
}
