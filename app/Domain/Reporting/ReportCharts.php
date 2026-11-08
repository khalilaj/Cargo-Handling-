<?php

namespace App\Domain\Reporting;

use App\Models\Tank;

/**
 * Turns the three report results into bar widths (percent) for the Reports page visuals.
 * Pure shaping: no figures are recalculated here.
 */
class ReportCharts
{
    public const MAX_ORDERS = 12;

    /**
     * @param  array{rows: list<array<string, mixed>>, totals: array<string, int>}  $stock
     * @param  list<array<string, mixed>>  $vessels
     * @param  list<array<string, mixed>>  $orders
     * @return array{stock: list<array<string, mixed>>, vessels: list<array<string, mixed>>, orders: list<array<string, mixed>>, hasData: bool}
     */
    public function __invoke(array $stock, array $vessels, array $orders): array
    {
        $capacity = Tank::query()->get()->groupBy(fn (Tank $t) => $t->commodity->value)
            ->map(fn ($tanks) => (int) $tanks->sum('capacity_kg'));

        $stockBars = [];
        foreach ($stock['rows'] as $r) {
            $cap = (int) ($capacity[$r['commodity']->value] ?? 0);
            $stockBars[] = [
                'label' => strtoupper($r['commodity']->value),
                'closing_kg' => $r['closing_kg'],
                'capacity_kg' => $cap,
                'pct' => self::pct($r['closing_kg'], $cap),
            ];
        }

        $vesselBars = array_map(function (array $v): array {
            $scale = max($v['bl_kg'], $v['received_gross_kg'], 1);
            $loss = $v['transit_loss_kg'];
            $net = $v['received_gross_kg'] - $loss;
            $lossPct = $loss > 0 ? max(1.5, self::pct($loss, $scale)) : 0.0;
            $netPct = min(self::pct($net, $scale), round(100 - $lossPct, 1));

            return [
                'label' => $v['voyage'].' · '.$v['vessel'],
                'net_kg' => $net,
                'loss_kg' => $loss,
                'outstanding_kg' => $v['outstanding_kg'],
                'net_pct' => $netPct,
                'loss_pct' => $lossPct,
                'outstanding_pct' => min(self::pct(max($v['outstanding_kg'], 0), $scale), round(100 - $netPct - $lossPct, 1)),
            ];
        }, $vessels);

        $orderBars = array_map(fn (array $o): array => [
            'label' => $o['order'].' · '.$o['customer'],
            'ordered_kg' => $o['ordered_kg'],
            'delivered_kg' => $o['delivered_kg'],
            'pct' => self::pct($o['delivered_kg'], $o['ordered_kg']),
        ], array_slice($orders, 0, self::MAX_ORDERS));

        $hasData = $vesselBars !== [] || $orderBars !== [] || $stock['totals']['closing_kg'] > 0
            || $stock['totals']['discharged_gross_kg'] > 0 || $stock['totals']['delivered_kg'] > 0;

        return ['stock' => $stockBars, 'vessels' => $vesselBars, 'orders' => $orderBars, 'hasData' => $hasData];
    }

    private static function pct(int|float $part, int|float $whole): float
    {
        return $whole > 0 ? min(100.0, round($part / $whole * 100, 1)) : 0.0;
    }
}
