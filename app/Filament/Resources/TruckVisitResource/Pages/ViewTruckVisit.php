<?php

namespace App\Filament\Resources\TruckVisitResource\Pages;

use App\Filament\Resources\TruckVisitResource;
use App\Filament\Support\TruckVisitActions;
use Filament\Resources\Pages\ViewRecord;

class ViewTruckVisit extends ViewRecord
{
    protected static string $resource = TruckVisitResource::class;

    protected function getHeaderActions(): array
    {
        return TruckVisitActions::forVisit();
    }
}
