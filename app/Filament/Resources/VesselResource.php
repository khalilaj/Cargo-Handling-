<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RelationManagers\VesselVoyagesRelationManager;
use App\Filament\Resources\VesselResource\Pages;
use App\Filament\Support\RegisterActions;
use App\Models\Vessel;
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

class VesselResource extends Resource
{
    protected static ?string $model = Vessel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static string|UnitEnum|null $navigationGroup = 'Registers';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')->required()->maxLength(120),
            Forms\Components\TextInput::make('imo')->label('IMO number')->regex('/^\d{7}$/')->unique(ignoreRecord: true)
                ->validationMessages(['regex' => 'An IMO number is exactly 7 digits.'])
                ->helperText('The ship\'s permanent identity. Optional, but unique when given.'),
            Forms\Components\TextInput::make('flag')->maxLength(60),
            Forms\Components\TextInput::make('owner_agent')->label('Owner / agent')->maxLength(120),
            Forms\Components\TextInput::make('capacity_kg')->label('Cargo capacity (kg)')->numeric()->integer()->minValue(1),
            Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
            Forms\Components\Toggle::make('active')->default(true)->helperText('Inactive vessels are not offered when scheduling a voyage.'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Vessel')->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('imo')->label('IMO')->placeholder('—'),
                TextEntry::make('flag')->placeholder('—'),
                TextEntry::make('owner_agent')->label('Owner / agent')->placeholder('—'),
                TextEntry::make('capacity_kg')->label('Cargo capacity (kg)')->numeric()->placeholder('—'),
                TextEntry::make('active')->badge()->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')->color(fn ($state) => $state ? 'success' : 'gray'),
            ]),
            Section::make('Tracking')->columns(3)->schema([
                TextEntry::make('where')->label('Right now')
                    ->state(fn (Vessel $record) => self::whereIs($record)),
                TextEntry::make('voyage_count')->label('Voyages')->state(fn (Vessel $record) => $record->voyages()->count()),
                TextEntry::make('received')->label('Cargo received, all voyages (kg)')
                    ->state(fn (Vessel $record) => number_format($record->voyages()->withTotals()->get()->sum(fn ($v) => $v->receivedGrossKg()))),
            ]),
        ]);
    }

    public static function whereIs(Vessel $v): string
    {
        $voyage = $v->currentVoyage();

        return $voyage
            ? "{$voyage->status->label()} at berth ".($voyage->berth?->code ?? '?')." · {$voyage->voyage_ref}"
            : 'Not in port';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->poll('30s')
            ->recordUrl(fn (Vessel $record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('imo')->label('IMO')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('flag')->placeholder('—'),
                Tables\Columns\TextColumn::make('where')->label('Right now')->state(fn (Vessel $record) => self::whereIs($record)),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([RegisterActions::statusFilter()])
            ->recordActions([ViewAction::make(), EditAction::make(), ...RegisterActions::archiveActions()]);
    }

    public static function getRelations(): array
    {
        return [VesselVoyagesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVessels::route('/'),
            'create' => Pages\CreateVessel::route('/create'),
            'view' => Pages\ViewVessel::route('/{record}'),
            'edit' => Pages\EditVessel::route('/{record}/edit'),
        ];
    }
}
