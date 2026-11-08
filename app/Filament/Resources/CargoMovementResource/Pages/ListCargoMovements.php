<?php

namespace App\Filament\Resources\CargoMovementResource\Pages;

use App\Filament\Resources\CargoMovementResource;
use Filament\Resources\Pages\ListRecords;

/** The ledger is read here. Cargo is booked through the voyage and delivery order wizards, so there are no booking buttons. */
class ListCargoMovements extends ListRecords
{
    protected static string $resource = CargoMovementResource::class;
}
