<?php

namespace App\Filament\Resources\TankResource\Pages;

use App\Filament\Resources\TankResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTank extends CreateRecord
{
    protected static string $resource = TankResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['current_volume_kg'] = $data['opening_volume_kg'] ?? 0;

        return $data;
    }
}
