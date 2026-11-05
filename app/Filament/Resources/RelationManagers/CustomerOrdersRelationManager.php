<?php

namespace App\Filament\Resources\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Delivery orders';

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
                Tables\Columns\TextColumn::make('order_ref')->label('Order')->weight('bold'),
                Tables\Columns\TextColumn::make('commodity')->badge()->formatStateUsing(fn ($state) => strtoupper($state->value)),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => $state->color()),
                Tables\Columns\TextColumn::make('ordered_kg')->label('Ordered (kg)')->numeric(),
                Tables\Columns\TextColumn::make('scheduled_at')->label('Scheduled')->dateTime('d M Y H:i')->placeholder('—'),
            ]);
    }
}
