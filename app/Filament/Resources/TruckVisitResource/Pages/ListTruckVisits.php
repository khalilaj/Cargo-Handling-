<?php

namespace App\Filament\Resources\TruckVisitResource\Pages;

use App\Filament\Resources\TruckVisitResource;
use App\Filament\Support\TruckVisitActions;
use Filament\Resources\Pages\ListRecords;

class ListTruckVisits extends ListRecords
{
    protected static string $resource = TruckVisitResource::class;

    protected function getHeaderActions(): array
    {
        return [TruckVisitActions::gateIn()];
    }
}
