<?php

namespace App\Filament\Resources\BerthResource\Pages;

use App\Filament\Resources\BerthResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBerths extends ListRecords
{
    protected static string $resource = BerthResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add berth')];
    }
}
