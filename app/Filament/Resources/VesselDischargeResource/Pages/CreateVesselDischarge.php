<?php

namespace App\Filament\Resources\VesselDischargeResource\Pages;

use App\Domain\Operations\Enums\DischargeStatus;
use App\Filament\Resources\VesselDischargeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVesselDischarge extends CreateRecord
{
    protected static string $resource = VesselDischargeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = DischargeStatus::Scheduled->value;

        return $data;
    }

    /** A new voyage goes straight into the wizard, on its first step. */
    protected function getRedirectUrl(): string
    {
        return VesselDischargeResource::getUrl('run', ['record' => $this->getRecord()]);
    }
}
