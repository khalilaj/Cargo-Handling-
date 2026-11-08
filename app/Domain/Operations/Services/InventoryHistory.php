<?php

namespace App\Domain\Operations\Services;

use App\Domain\Cargo\Enums\Commodity;
use App\Models\CargoMovement;
use App\Models\Tank;
use App\Support\KenyaTime;
use Carbon\CarbonImmutable;

/** Rebuilds stock levels at any past moment by replaying the ledger. */
class InventoryHistory
{
    /**
     * Closing stock (kg) per commodity at the end of each of the last $days days.
     *
     * @return array{labels: list<string>, series: array<string, list<int>>}
     */
    public function dailyByCommodity(int $days = 14): array
    {
        $ends = collect(range($days - 1, 0))->map(fn ($i) => CarbonImmutable::now()->subDays($i)->endOfDay());

        $opening = Tank::query()->get()->groupBy(fn (Tank $t) => $t->commodity->value)
            ->map(fn ($g) => (int) $g->sum('opening_volume_kg'));

        $moves = CargoMovement::query()->with('tank:id,commodity')->orderBy('occurred_at')->get();

        $series = [];
        foreach (Commodity::cases() as $c) {
            $mine = $moves->filter(fn (CargoMovement $m) => $m->tank->commodity === $c);
            $series[$c->value] = $ends->map(fn ($end) => (int) ($opening[$c->value] ?? 0)
                + $mine->filter(fn ($m) => $m->occurred_at->lte($end))->sum(fn ($m) => $m->delta()))->all();
        }

        return ['labels' => $ends->map(fn ($d) => $d->format('d M'))->all(), 'series' => $series];
    }

    /**
     * Level of one tank after every movement (for the per-tank balance chart).
     *
     * @return array{labels: list<string>, levels: list<int>}
     */
    public function forTank(Tank $tank): array
    {
        $level = $tank->opening_volume_kg;
        $labels = ['Opening'];
        $levels = [$level];

        foreach ($tank->movements()->orderBy('occurred_at')->orderBy('id')->get() as $m) {
            $level += $m->delta();
            $labels[] = KenyaTime::of($m->occurred_at)->format('d M H:i');
            $levels[] = $level;
        }

        return ['labels' => $labels, 'levels' => $levels];
    }
}
