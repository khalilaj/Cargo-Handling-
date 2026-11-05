<?php

namespace App\Filament\Resources\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Read-only ledger lines for any parent that has a `movements` relation. */
class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Ledger movements';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->poll('5s')
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')->label('When')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('type')->badge()
                    ->state(fn ($record) => $record->isReversal() ? 'Reversal' : ($record->type->isInbound() ? 'Discharge' : 'Delivery'))
                    ->color(fn ($state) => match ($state) {
                        'Reversal' => 'gray', 'Discharge' => 'info', default => 'warning'
                    }),
                Tables\Columns\TextColumn::make('tank.code')->label('Tank'),
                Tables\Columns\TextColumn::make('reference')->label('Vessel / truck'),
                Tables\Columns\TextColumn::make('gross_kg')->label('Gross (kg)')->numeric(),
                Tables\Columns\TextColumn::make('loss_kg')->label('Loss (kg)')->numeric(),
                Tables\Columns\TextColumn::make('net_kg')->label('Net (kg)')->numeric(),
            ]);
    }
}
