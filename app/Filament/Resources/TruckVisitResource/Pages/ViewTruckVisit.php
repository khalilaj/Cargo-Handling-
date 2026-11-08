<?php

namespace App\Filament\Resources\TruckVisitResource\Pages;

use App\Filament\Resources\DeliveryOrderResource;
use App\Filament\Resources\TruckVisitResource;
use App\Filament\Support\ContinueAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTruckVisit extends ViewRecord
{
    protected static string $resource = TruckVisitResource::class;

    protected function getHeaderActions(): array
    {
        // A truck's steps live in its order's wizard, on the right truck.
        return [ContinueAction::make(DeliveryOrderResource::class, fn ($visit) => DeliveryOrderResource::getUrl('run', ['record' => $visit->delivery_order_id, 'visit' => $visit->id]))];
    }
}
