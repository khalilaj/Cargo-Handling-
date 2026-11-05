<?php

namespace App\Filament\Widgets;

use App\Domain\Fleet\Enums\VisitStatus;
use App\Filament\Resources\TruckVisitResource;
use App\Models\TruckVisit;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TrucksOnSite extends TableWidget
{
    protected static ?int $sort = 4;

    protected static ?string $heading = 'Trucks on site';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(TruckVisit::query()->withTotals()->with(['truck', 'driver', 'order'])->whereNotNull('active_truck_id')->orderBy('gate_in_at'))
            ->paginated(false)
            ->poll('10s')
            ->emptyStateHeading('No trucks on site')
            ->recordUrl(fn (TruckVisit $record) => TruckVisitResource::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('truck.plate')->label('Truck')->weight('bold'),
                Tables\Columns\TextColumn::make('driver.name')->label('Driver')->placeholder('—'),
                Tables\Columns\TextColumn::make('order.order_ref')->label('Order')->description(fn (TruckVisit $r) => $r->order->customer),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (VisitStatus $state) => $state->label())->color(fn (VisitStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('loaded_kg')->label('Loaded (kg)')->numeric()->default(0),
                Tables\Columns\TextColumn::make('warning_count')->label('Warnings')->badge()
                    ->state(fn (TruckVisit $r) => count($r->warnings ?? []) ?: null)->color('warning')
                    ->tooltip(fn (TruckVisit $r) => implode("\n", $r->warnings ?? [])),
                Tables\Columns\TextColumn::make('gate_in_at')->label('On site')->since(),
            ]);
    }
}
