<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RelationManagers\TransporterDriversRelationManager;
use App\Filament\Resources\RelationManagers\TransporterTrucksRelationManager;
use App\Filament\Resources\TransporterResource\Pages;
use App\Filament\Support\RegisterActions;
use App\Models\Transporter;
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

class TransporterResource extends Resource
{
    protected static ?string $model = Transporter::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string|UnitEnum|null $navigationGroup = 'Registers';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')->required()->unique(ignoreRecord: true)->maxLength(120),
            Forms\Components\TextInput::make('phone')->tel()->maxLength(40),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Transporter')->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('active')->badge()->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')->color(fn ($state) => $state ? 'success' : 'gray'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['trucks', 'drivers']))
            ->recordUrl(fn (Transporter $record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('phone')->placeholder('—'),
                Tables\Columns\TextColumn::make('trucks_count')->label('Trucks')->numeric(),
                Tables\Columns\TextColumn::make('drivers_count')->label('Drivers')->numeric(),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([Tables\Filters\TernaryFilter::make('active')])
            ->recordActions([ViewAction::make(), EditAction::make(), RegisterActions::toggleActive()]);
    }

    public static function getRelations(): array
    {
        return [TransporterTrucksRelationManager::class, TransporterDriversRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransporters::route('/'),
            'create' => Pages\CreateTransporter::route('/create'),
            'view' => Pages\ViewTransporter::route('/{record}'),
            'edit' => Pages\EditTransporter::route('/{record}/edit'),
        ];
    }
}
