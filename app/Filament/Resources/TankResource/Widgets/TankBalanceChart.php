<?php

namespace App\Filament\Resources\TankResource\Widgets;

use App\Domain\Operations\Services\InventoryHistory;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;

/** Level of one tank after every ledger movement. Lives outside Widgets/ so it is not auto-added to the dashboard. */
class TankBalanceChart extends ChartWidget
{
    public ?Model $record = null;

    protected ?string $heading = 'Balance history';

    protected ?string $description = 'Tank level in tonnes after each movement, rebuilt from the ledger.';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $h = app(InventoryHistory::class)->forTank($this->record);

        return [
            'labels' => $h['labels'],
            'datasets' => [[
                'label' => $this->record->code.' (t)',
                'data' => array_map(fn ($kg) => round($kg / 1000, 1), $h['levels']),
                'borderColor' => '#0d9488',
                'backgroundColor' => 'rgba(13,148,136,.15)',
                'fill' => true,
                'stepped' => true,
                'pointRadius' => 3,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
