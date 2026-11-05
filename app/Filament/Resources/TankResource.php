<?php

namespace App\Filament\Resources;

use App\Domain\Cargo\Enums\Commodity;
use App\Filament\Resources\TankResource\Pages;
use App\Models\Tank;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TankResource extends Resource
{
    protected static ?string $model = Tank::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-beaker';
    protected static ?string $navigationLabel = 'Storage tanks';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('code')->required()->unique(ignoreRecord: true)->maxLength(20),
            Forms\Components\TextInput::make('name')->required(),
            Forms\Components\Select::make('commodity')
                ->options(collect(Commodity::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                ->required()->live()
                ->afterStateUpdated(fn ($state, Set $set) => $set(
                    'requires_heating', Commodity::tryFrom($state ?? '')?->requiresHeating() ?? false
                )),
            Forms\Components\TextInput::make('capacity_kg')->label('Capacity (kg)')
                ->numeric()->integer()->minValue(1)->required(),
            Forms\Components\TextInput::make('opening_volume_kg')->label('Opening volume (kg)')
                ->numeric()->integer()->minValue(0)->default(0)
                ->lte('capacity_kg')
                ->helperText('Stock in the tank before the first recorded movement.'),
            Forms\Components\Toggle::make('requires_heating')->label('Temperature control (CPO)'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->columns(self::levelColumns())
            ->recordActions([EditAction::make()]);
    }

    /** Also reused by the dashboard widget. */
    public static function levelColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('code')->searchable()->weight('bold'),
            Tables\Columns\TextColumn::make('commodity')->badge()
                ->formatStateUsing(fn (Commodity $state) => strtoupper($state->value)),
            Tables\Columns\TextColumn::make('current_volume_kg')->label('In tank (kg)')->numeric(),
            Tables\Columns\TextColumn::make('capacity_kg')->label('Capacity (kg)')->numeric(),
            Tables\Columns\TextColumn::make('fill')->label('Filled')
                ->state(fn (Tank $t) => $t->fillPercent() . '%')
                ->badge()
                ->color(fn (Tank $t) => match (true) {
                    $t->fillPercent() >= 90 => 'danger',
                    $t->fillPercent() >= 70 => 'warning',
                    default => 'success',
                }),
            Tables\Columns\IconColumn::make('requires_heating')->label('Heated')->boolean(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTanks::route('/'),
            'create' => Pages\CreateTank::route('/create'),
            'edit' => Pages\EditTank::route('/{record}/edit'),
        ];
    }
}
