<?php

namespace App\Filament\Resources;

use App\Domain\Cargo\Enums\MovementType;
use App\Filament\Resources\CargoMovementResource\Pages;
use App\Models\CargoMovement;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CargoMovementResource extends Resource
{
    protected static ?string $model = CargoMovement::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Cargo movements';

    /** Movements are an immutable ledger: no create/edit/delete screens. */
    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['tank', 'vesselDischarge', 'deliveryOrder']))
            ->defaultSort('occurred_at', 'desc')
            ->poll('10s')
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')->label('When')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('type')->badge()
                    ->formatStateUsing(fn (MovementType $state) => $state->label())
                    ->color(fn (MovementType $state) => $state->isInbound() ? 'info' : 'warning'),
                Tables\Columns\TextColumn::make('tank.code')->label('Tank')->searchable(),
                Tables\Columns\TextColumn::make('operation')->label('Operation')
                    ->state(fn (CargoMovement $m) => $m->vesselDischarge?->voyage_ref ?? $m->deliveryOrder?->order_ref ?? 'Ad hoc'),
                Tables\Columns\TextColumn::make('reference')->label('Vessel / truck')->searchable(),
                Tables\Columns\TextColumn::make('gross_kg')->label('Gross (kg)')->numeric(),
                Tables\Columns\TextColumn::make('loss_kg')->label('Transit loss (kg)')->numeric(),
                Tables\Columns\TextColumn::make('net_kg')->label('Net (kg)')->numeric(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(collect(MovementType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])),
                Tables\Filters\SelectFilter::make('tank_id')->label('Tank')->relationship('tank', 'code'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCargoMovements::route('/')];
    }
}
