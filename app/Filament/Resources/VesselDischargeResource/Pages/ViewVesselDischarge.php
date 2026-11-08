<?php

namespace App\Filament\Resources\VesselDischargeResource\Pages;

use App\Filament\Resources\VesselDischargeResource;
use App\Filament\Support\ContinueAction;
use Filament\Resources\Pages\ViewRecord;

class ViewVesselDischarge extends ViewRecord
{
    protected static string $resource = VesselDischargeResource::class;

    protected function getHeaderActions(): array
    {
        return [ContinueAction::make(VesselDischargeResource::class)];
    }
}
