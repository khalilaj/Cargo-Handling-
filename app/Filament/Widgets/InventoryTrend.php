<?php

namespace App\Filament\Widgets;

use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Operations\Services\InventoryHistory;
use Filament\Widgets\ChartWidget;

class InventoryTrend extends ChartWidget
{
    protected static ?int $sort = 3;
    protected int|string|array $columnSpan = 1;
    protected ?string $heading = 'Inventory, last 14 days';
    protected ?string $description = 'Closing stock per product, in tonnes (rebuilt from the ledger).';
    protected ?string $pollingInterval = '30s';
    protected ?string $maxHeight = '260px';

    private const COLORS = ['cpo' => '#d97706', 'cpol' => '#0d9488', 'pfad' => '#7c3aed'];

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $h = app(InventoryHistory::class)->dailyByCommodity(14);

        return [
            'labels' => $h['labels'],
            'datasets' => collect(Commodity::cases())->map(fn (Commodity $c) => [
                'label' => strtoupper($c->value),
                'data' => array_map(fn ($kg) => round($kg / 1000, 1), $h['series'][$c->value]),
                'borderColor' => self::COLORS[$c->value],
                'backgroundColor' => self::COLORS[$c->value],
                'tension' => 0.3,
                'pointRadius' => 2,
            ])->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'tonnes']]],
        ];
    }
}
