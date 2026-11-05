<?php

namespace App\Filament\Resources\BerthResource\Pages;

use App\Filament\Resources\BerthResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBerth extends ViewRecord
{
    protected static string $resource = BerthResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
