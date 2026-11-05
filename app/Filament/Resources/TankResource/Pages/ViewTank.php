<?php

namespace App\Filament\Resources\TankResource\Pages;

use App\Filament\Resources\TankResource;
use App\Filament\Resources\TankResource\Widgets\TankBalanceChart;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTank extends ViewRecord
{
    protected static string $resource = TankResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }

    protected function getHeaderWidgets(): array
    {
        return [TankBalanceChart::class];
    }
}
