<?php

namespace App\Filament\Resources\TankResource\Pages;

use App\Filament\Resources\TankResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTanks extends ListRecords
{
    protected static string $resource = TankResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
