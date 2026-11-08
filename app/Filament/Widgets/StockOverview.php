<?php

namespace App\Filament\Widgets;

use App\Domain\Cargo\Enums\Commodity;
use App\Models\CargoMovement;
use App\Models\Tank;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StockOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '10s';

    protected function getStats(): array
    {
        $stats = [];
        foreach (Commodity::cases() as $c) {
            $stock = (int) Tank::where('commodity', $c)->sum('current_volume_kg');
            $cap = (int) Tank::where('commodity', $c)->where('active', true)->sum('capacity_kg');
            $stats[] = Stat::make(strtoupper($c->value).' in storage', number_format($stock / 1000, 1).' t')
                ->description('of '.number_format($cap / 1000, 1).' t capacity');
        }
        $stats[] = Stat::make('Transit loss to date', number_format((int) CargoMovement::sum(DB::raw('CASE WHEN reverses_movement_id IS NULL THEN loss_kg ELSE -loss_kg END')) / 1000, 2).' t')
            ->description('0.5% of every vessel discharge')->color('danger');

        return $stats;
    }
}
