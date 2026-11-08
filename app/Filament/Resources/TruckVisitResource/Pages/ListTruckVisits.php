<?php

namespace App\Filament\Resources\TruckVisitResource\Pages;

use App\Filament\Resources\DeliveryOrderResource;
use App\Filament\Resources\TruckVisitResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListTruckVisits extends ListRecords
{
    protected static string $resource = TruckVisitResource::class;

    protected function getHeaderActions(): array
    {
        // A truck is gated in against an order, from that order's wizard.
        return [
            Action::make('gate_in')->label('Gate in a truck')->icon('heroicon-o-arrow-right-end-on-rectangle')
                ->tooltip('Pick the order, then gate the truck in from its page')
                ->authorize(fn () => auth()->user()?->role->canOperate() ?? false)
                ->url(DeliveryOrderResource::getUrl()),
        ];
    }
}
