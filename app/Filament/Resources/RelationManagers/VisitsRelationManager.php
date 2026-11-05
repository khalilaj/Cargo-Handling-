<?php

namespace App\Filament\Resources\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class VisitsRelationManager extends RelationManager
{
    protected static string $relationship = 'visits';

    protected static ?string $title = 'Site visits';

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
                Tables\Columns\TextColumn::make('id')->label('Visit')->formatStateUsing(fn ($state) => 'TV-'.str_pad((string) $state, 5, '0', STR_PAD_LEFT))->weight('bold'),
                Tables\Columns\TextColumn::make('order.order_ref')->label('Order'),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => $state->color()),
                Tables\Columns\TextColumn::make('gate_in_at')->label('Gate in')->dateTime('d M H:i'),
                Tables\Columns\TextColumn::make('gate_out_at')->label('Gate out')->dateTime('d M H:i')->placeholder('—'),
            ]);
    }
}
