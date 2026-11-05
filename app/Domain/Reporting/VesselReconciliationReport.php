<?php

namespace App\Domain\Reporting;

use App\Models\VesselDischarge;
use Carbon\CarbonInterface;

/** Bill of lading vs what the shore received, per voyage. */
class VesselReconciliationReport
{
    /** @return list<array<string, mixed>> */
    public function __invoke(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        return VesselDischarge::withTotals()->orderBy('id')->get()
            ->filter(function (VesselDischarge $v) use ($from, $to) {
                $when = $v->started_at ?? $v->berthed_at ?? $v->eta ?? $v->created_at;

                return $when->between($from, $to);
            })
            ->map(fn (VesselDischarge $v) => [
                'voyage' => $v->voyage_ref,
                'vessel' => $v->vessel_name,
                'commodity' => strtoupper($v->commodity->value),
                'status' => $v->status->label(),
                'bl_kg' => $v->bl_quantity_kg,
                'received_gross_kg' => $v->receivedGrossKg(),
                'transit_loss_kg' => $v->transitLossKg(),
                'received_net_kg' => $v->receivedNetKg(),
                'outstanding_kg' => $v->remainingKg(),
                'loss_percent' => $v->receivedGrossKg() > 0 ? round($v->transitLossKg() / $v->receivedGrossKg() * 100, 2) : 0.0,
            ])
            ->values()
            ->all();
    }
}
