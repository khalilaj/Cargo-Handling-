<?php

namespace App\Domain\Reporting;

use App\Models\DeliveryOrder;
use Carbon\CarbonInterface;

/** Ordered vs delivered, per delivery order. */
class DeliveryFulfilmentReport
{
    /** @return list<array<string, mixed>> */
    public function __invoke(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        return DeliveryOrder::withTotals()->orderBy('id')->get()
            ->filter(function (DeliveryOrder $o) use ($from, $to) {
                $when = $o->started_at ?? $o->scheduled_at ?? $o->created_at;

                return $when->between($from, $to);
            })
            ->map(fn (DeliveryOrder $o) => [
                'order' => $o->order_ref,
                'customer' => $o->customer,
                'commodity' => strtoupper($o->commodity->value),
                'status' => $o->status->label(),
                'ordered_kg' => $o->ordered_kg,
                'delivered_kg' => $o->deliveredKg(),
                'outstanding_kg' => $o->remainingKg(),
                'fulfilled_percent' => $o->progressPercent(),
            ])
            ->values()
            ->all();
    }
}
