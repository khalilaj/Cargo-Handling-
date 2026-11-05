<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BerthResource\Pages;
use App\Filament\Support\RegisterActions;
use App\Models\Berth;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class BerthResource extends Resource
{
    protected static ?string $model = Berth::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static string|UnitEnum|null $navigationGroup = 'Registers';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('code')->required()->unique(ignoreRecord: true)->maxLength(20),
            Forms\Components\TextInput::make('name')->required()->maxLength(120),
            Forms\Components\Toggle::make('active')->default(true)->label('In service'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Berth')->columns(3)->schema([
                TextEntry::make('code'),
                TextEntry::make('name'),
                TextEntry::make('occupied')->label('Right now')->state(fn (Berth $record) => self::occupancy($record)),
            ]),
        ]);
    }

    public static function occupancy(Berth $b): string
    {
        $v = $b->occupant();

        return $v ? "{$v->vessel_name} ({$v->voyage_ref}, {$v->status->value})" : 'Free';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->poll('30s')
            ->recordUrl(fn (Berth $record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('occupied')->label('Right now')->state(fn (Berth $record) => self::occupancy($record))
                    ->badge()->color(fn (string $state) => $state === 'Free' ? 'success' : 'warning'),
                Tables\Columns\IconColumn::make('active')->label('In service')->boolean(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make(), RegisterActions::toggleActive()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBerths::route('/'),
            'create' => Pages\CreateBerth::route('/create'),
            'view' => Pages\ViewBerth::route('/{record}'),
            'edit' => Pages\EditBerth::route('/{record}/edit'),
        ];
    }
}
