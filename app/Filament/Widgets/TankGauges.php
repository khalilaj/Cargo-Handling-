<?php

namespace App\Filament\Widgets;

use App\Models\Tank;
use Filament\Widgets\Widget;

class TankGauges extends Widget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.tank-gauges';

    protected function getViewData(): array
    {
        return ['tanks' => Tank::where('active', true)->orderBy('code')->get()];
    }
}
