<?php

namespace App\Filament\Resources\TransporterResource\Pages;

use App\Filament\Resources\TransporterResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTransporter extends ViewRecord
{
    protected static string $resource = TransporterResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
