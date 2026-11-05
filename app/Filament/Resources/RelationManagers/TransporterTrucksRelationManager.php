<?php

namespace App\Filament\Resources\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TransporterTrucksRelationManager extends RelationManager
{
    protected static string $relationship = 'trucks';

    protected static ?string $title = 'Trucks';

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
                Tables\Columns\TextColumn::make('plate')->weight('bold'),
                Tables\Columns\TextColumn::make('capacity_kg')->label('Payload (kg)')->numeric()->placeholder('—'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ]);
    }
}
