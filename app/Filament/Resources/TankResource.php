<?php

namespace App\Filament\Resources;

use App\Domain\Cargo\Enums\Commodity;
use App\Filament\Resources\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\TankResource\Pages;
use App\Models\Tank;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class TankResource extends Resource
{
    protected static ?string $model = Tank::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Storage tanks';

    protected static ?string $recordTitleAttribute = 'code';

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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tank')->columns(4)->schema([
                TextEntry::make('code'),
                TextEntry::make('name'),
                TextEntry::make('commodity')->badge()->formatStateUsing(fn (Commodity $state) => strtoupper($state->value)),
                TextEntry::make('requires_heating')->label('Temperature control')
                    ->formatStateUsing(fn ($state) => $state ? 'Heated (CPO)' : 'Not required'),
            ]),
            Section::make('Level')->columns(4)->schema([
                TextEntry::make('current_volume_kg')->label('In tank (kg)')->numeric(),
                TextEntry::make('capacity_kg')->label('Capacity (kg)')->numeric(),
                TextEntry::make('free')->label('Free space (kg)')
                    ->state(fn (Tank $t) => number_format($t->capacity_kg - $t->current_volume_kg)),
                TextEntry::make('fill')->label('Filled')->state(fn (Tank $t) => $t->fillPercent().'%')->badge()
                    ->color(fn (Tank $t) => $t->fillPercent() >= 90 ? 'danger' : ($t->fillPercent() >= 70 ? 'warning' : 'success')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->recordUrl(fn (Tank $t) => static::getUrl('view', ['record' => $t]))
            ->columns(self::levelColumns())
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [MovementsRelationManager::class];
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
                ->state(fn (Tank $t) => $t->fillPercent().'%')
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
            'view' => Pages\ViewTank::route('/{record}'),
            'edit' => Pages\EditTank::route('/{record}/edit'),
        ];
    }
}
