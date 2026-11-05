<?php

namespace App\Filament\Resources\TruckResource\Pages;

use App\Filament\Resources\TruckResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTruck extends ViewRecord
{
    protected static string $resource = TruckResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
