<?php

namespace App\Filament\Resources\VesselDischargeResource\Pages;

use App\Filament\Resources\VesselDischargeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVesselDischarges extends ListRecords
{
    protected static string $resource = VesselDischargeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Schedule vessel')];
    }
}
