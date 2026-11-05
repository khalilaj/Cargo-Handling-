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
}
