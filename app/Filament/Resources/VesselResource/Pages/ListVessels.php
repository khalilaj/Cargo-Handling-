<?php

namespace App\Filament\Resources\VesselResource\Pages;

use App\Filament\Resources\VesselResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVessels extends ListRecords
{
    protected static string $resource = VesselResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add vessel')];
    }
}
