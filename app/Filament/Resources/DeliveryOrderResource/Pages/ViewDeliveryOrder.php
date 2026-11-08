<?php

namespace App\Filament\Resources\DeliveryOrderResource\Pages;

use App\Filament\Resources\DeliveryOrderResource;
use App\Filament\Support\ContinueAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDeliveryOrder extends ViewRecord
{
    protected static string $resource = DeliveryOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [ContinueAction::make(DeliveryOrderResource::class)];
    }
}
