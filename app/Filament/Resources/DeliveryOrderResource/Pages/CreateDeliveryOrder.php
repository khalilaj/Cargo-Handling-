<?php

namespace App\Filament\Resources\DeliveryOrderResource\Pages;

use App\Domain\Operations\Enums\OrderStatus;
use App\Filament\Resources\DeliveryOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDeliveryOrder extends CreateRecord
{
    protected static string $resource = DeliveryOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = OrderStatus::Pending->value;

        return $data;
    }

    /** A new order goes straight into the wizard, on its first step. */
    protected function getRedirectUrl(): string
    {
        return DeliveryOrderResource::getUrl('run', ['record' => $this->getRecord()]);
    }
}
