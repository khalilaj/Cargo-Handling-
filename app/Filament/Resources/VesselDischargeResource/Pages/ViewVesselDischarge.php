<?php

namespace App\Filament\Resources\VesselDischargeResource\Pages;

use App\Filament\Resources\VesselDischargeResource;
use App\Filament\Support\OperationActions;
use Filament\Resources\Pages\ViewRecord;

class ViewVesselDischarge extends ViewRecord
{
    protected static string $resource = VesselDischargeResource::class;

    protected function getHeaderActions(): array
    {
        return OperationActions::discharge();
    }
}
