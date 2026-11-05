<?php

namespace App\Filament\Resources\CorrectionRequestResource\Pages;

use App\Filament\Resources\CorrectionRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCorrectionRequest extends ViewRecord
{
    protected static string $resource = CorrectionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return CorrectionRequestResource::decisionActions();
    }
}
