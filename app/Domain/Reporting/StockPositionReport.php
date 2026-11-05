<?php

namespace App\Domain\Reporting;

use App\Domain\Cargo\Enums\Commodity;
use App\Models\CargoMovement;
use App\Models\Tank;
use Carbon\CarbonInterface;

/**
 * Stock position per product for a period, rebuilt from the ledger.
 *
 *   closing = opening + discharged (gross) - transit loss - delivered
 *
 * Reversals are folded into the figure they cancel (a reversed discharge reduces "discharged"
 * and its loss; a reversed delivery reduces "delivered"), so the identity always holds and the
 * closing figure must equal what the tanks hold at the end of the period.
 */
class StockPositionReport
{
    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function __invoke(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $tanks = Tank::query()->get()->groupBy(fn (Tank $t) => $t->commodity->value);
        $moves = CargoMovement::query()->with('tank:id,commodity')->get();

        $rows = [];
        foreach (Commodity::cases() as $c) {
            $mine = $moves->filter(fn (CargoMovement $m) => $m->tank->commodity === $c);
            $before = $mine->filter(fn ($m) => $m->occurred_at->lt($from));
            $during = $mine->filter(fn ($m) => $m->occurred_at->gte($from) && $m->occurred_at->lte($to));

            $opening = (int) ($tanks[$c->value] ?? collect())->sum('opening_volume_kg') + $before->sum(fn ($m) => $m->delta());

            $discharged = $this->sum($during, fn ($m) => $m->type->isInbound() && ! $m->isReversal(), 'gross_kg')
                - $this->sum($during, fn ($m) => ! $m->type->isInbound() && $m->isReversal(), 'gross_kg');
            $loss = $this->sum($during, fn ($m) => $m->type->isInbound() && ! $m->isReversal(), 'loss_kg')
                - $this->sum($during, fn ($m) => ! $m->type->isInbound() && $m->isReversal(), 'loss_kg');
            $delivered = $this->sum($during, fn ($m) => ! $m->type->isInbound() && ! $m->isReversal(), 'net_kg')
                - $this->sum($during, fn ($m) => $m->type->isInbound() && $m->isReversal(), 'net_kg');

            $closing = $opening + $discharged - $loss - $delivered;

            $rows[] = [
                'commodity' => $c,
                'opening_kg' => $opening,
                'discharged_gross_kg' => $discharged,
                'transit_loss_kg' => $loss,
                'delivered_kg' => $delivered,
                'closing_kg' => $closing,
                'reversals' => $during->filter(fn ($m) => $m->isReversal())->count(),
                // Independent check: replaying the ledger must give the same answer.
                'reconciles' => $closing === $opening + $during->sum(fn ($m) => $m->delta()),
            ];
        }

        $totals = [];
        foreach (['opening_kg', 'discharged_gross_kg', 'transit_loss_kg', 'delivered_kg', 'closing_kg', 'reversals'] as $k) {
            $totals[$k] = (int) array_sum(array_column($rows, $k));
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    private function sum($movements, callable $filter, string $column): int
    {
        return (int) $movements->filter($filter)->sum($column);
    }
}
