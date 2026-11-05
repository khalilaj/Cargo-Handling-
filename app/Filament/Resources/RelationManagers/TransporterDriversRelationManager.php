<?php

namespace App\Filament\Resources\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TransporterDriversRelationManager extends RelationManager
{
    protected static string $relationship = 'drivers';

    protected static ?string $title = 'Drivers';

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
                Tables\Columns\TextColumn::make('name')->weight('bold'),
                Tables\Columns\TextColumn::make('licence_number')->label('Licence'),
                Tables\Columns\TextColumn::make('licence_expiry')->label('Expires')->date('d M Y')->placeholder('—'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ]);
    }
}
