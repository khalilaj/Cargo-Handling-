<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Cargo\Enums\MovementType;
use App\Domain\Cargo\Services\TankLedger;
use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Fleet\Exceptions\InvalidVisitException;
use App\Domain\Fleet\Exceptions\TruckOverloadedException;
use App\Domain\Operations\Exceptions\OperationNotActiveException;
use App\Domain\Operations\Exceptions\OrderQuantityExceededException;
use App\Models\CargoMovement;
use App\Models\DeliveryOrder;
use App\Models\TruckVisit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Load part of a delivery order from a tank onto a truck. Lock order: order -> tank. */
class RecordTruckLoad
{
    public function __construct(private TankLedger $ledger) {}

    public function __invoke(
        DeliveryOrder $order, int $tankId, int $kg, CarbonInterface $occurredAt,
        string $idempotencyKey, ?string $truck = null, ?TruckVisit $visit = null,
    ): CargoMovement {
        return DB::transaction(function () use ($order, $tankId, $kg, $occurredAt, $idempotencyKey, $truck, $visit) {
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

            $links = ['delivery_order_id' => $o->id];

            // Lock order: order -> visit -> tank, always, so concurrent loads cannot deadlock.
            if ($visit) {
                $v = TruckVisit::query()->lockForUpdate()->with('truck')->findOrFail($visit->id);

                if ($v->delivery_order_id !== $o->id) {
                    throw new InvalidVisitException("{$v->ref} is for a different order than {$o->order_ref}.");
                }
                if ($v->status !== VisitStatus::Loading) {
                    throw new InvalidVisitException("{$v->ref} is {$v->status->label()}; the truck must be in the loading bay first.");
                }

                $payload = $v->truck->capacity_kg;
                if ($payload !== null && $v->loadedKg() + $kg > $payload) {
                    throw new TruckOverloadedException(sprintf(
                        '%s would carry %s kg, over the %s kg payload of truck %s (room for %s kg more).',
                        $v->ref, number_format($v->loadedKg() + $kg), number_format($payload), $v->truck->plate,
                        number_format(max($payload - $v->loadedKg(), 0)),
                    ));
                }

                $truck = $v->truck->plate;
                $links['truck_visit_id'] = $v->id;
            }

            return $this->ledger->record(
                $tankId, MovementType::TruckDelivery, $kg, $occurredAt, $idempotencyKey,
                trim($o->order_ref.($truck ? " · {$truck}" : '')), $o->commodity,
                $links,
            );
        });
    }
}
