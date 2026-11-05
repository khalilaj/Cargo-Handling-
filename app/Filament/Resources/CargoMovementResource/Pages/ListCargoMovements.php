<?php

namespace App\Filament\Resources\CargoMovementResource\Pages;

use App\Filament\Resources\CargoMovementResource;
use App\Filament\Support\MovementForm;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListCargoMovements extends ListRecords
{
    protected static string $resource = CargoMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('discharge')
                ->label('Record vessel discharge')->icon('heroicon-o-arrow-down-tray')
                ->authorize(fn () => auth()->user()?->role->canOperate() ?? false)
                ->modalHeading('Vessel discharge (inbound) — 0.5% transit loss is applied')
                ->schema(MovementForm::schema('Vessel name'))
                ->action(fn (array $data) => MovementForm::discharge($data)),
            Action::make('delivery')
                ->label('Record truck delivery')->icon('heroicon-o-truck')->color('warning')
                ->authorize(fn () => auth()->user()?->role->canOperate() ?? false)
                ->modalHeading('Truck delivery (outbound)')
                ->schema(MovementForm::schema('Truck plate'))
                ->action(fn (array $data) => MovementForm::delivery($data)),
        ];
    }
}
