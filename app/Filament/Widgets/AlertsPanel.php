<?php

namespace App\Filament\Widgets;

use App\Domain\Operations\Services\OperationalAlerts;
use Filament\Widgets\Widget;

class AlertsPanel extends Widget
{
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 1;
    protected string $view = 'filament.widgets.alerts-panel';

    protected function getViewData(): array
    {
        return ['alerts' => app(OperationalAlerts::class)->all()];
    }
}
