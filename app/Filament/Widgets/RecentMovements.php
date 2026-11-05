<?php

namespace App\Filament\Widgets;

use App\Models\CargoMovement;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentMovements extends TableWidget
{
    protected static ?int $sort = 7;

    protected static ?string $heading = 'Latest movements';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(CargoMovement::query()->with(['tank', 'vesselDischarge', 'deliveryOrder'])->latest('occurred_at')->limit(8))
            ->paginated(false)
            ->poll('10s')
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')->label('When')->dateTime('d M H:i'),
                Tables\Columns\TextColumn::make('type')->badge()
                    ->state(fn ($record) => $record->isReversal() ? 'Reversal' : ($record->type->isInbound() ? 'Discharge' : 'Delivery'))
                    ->color(fn ($state) => match ($state) {
                        'Reversal' => 'gray', 'Discharge' => 'info', default => 'warning'
                    }),
                Tables\Columns\TextColumn::make('tank.code')->label('Tank'),
                Tables\Columns\TextColumn::make('operation')->label('Operation')
                    ->state(fn (CargoMovement $m) => $m->vesselDischarge?->voyage_ref ?? $m->deliveryOrder?->order_ref ?? 'Ad hoc'),
                Tables\Columns\TextColumn::make('reference')->label('Vessel / truck'),
                Tables\Columns\TextColumn::make('net_kg')->label('Net (kg)')->numeric(),
            ]);
    }
}
