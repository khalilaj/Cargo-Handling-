<?php

namespace App\Filament\Resources\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class VesselVoyagesRelationManager extends RelationManager
{
    protected static string $relationship = 'voyages';

    protected static ?string $title = 'Voyages';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('voyage_ref')->label('Voyage')->weight('bold'),
                Tables\Columns\TextColumn::make('commodity')->badge()->formatStateUsing(fn ($state) => strtoupper($state->value)),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => $state->color()),
                Tables\Columns\TextColumn::make('berth.code')->label('Berth')->placeholder('—'),
                Tables\Columns\TextColumn::make('bl_quantity_kg')->label('B/L (kg)')->numeric(),
                Tables\Columns\TextColumn::make('eta')->label('ETA')->dateTime('d M Y H:i')->placeholder('—'),
            ]);
    }
}
