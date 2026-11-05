<?php

namespace App\Filament\Resources;

use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Filament\Resources\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\VesselDischargeResource\Pages;
use App\Filament\Support\OperationActions;
use App\Models\VesselDischarge;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class VesselDischargeResource extends Resource
{
    protected static ?string $model = VesselDischarge::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-on-square-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Vessel discharges';

    protected static ?string $modelLabel = 'vessel discharge';

    protected static ?string $recordTitleAttribute = 'voyage_ref';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('voyage_ref')->label('Voyage reference')->required()->unique(ignoreRecord: true)->maxLength(40),
            Forms\Components\TextInput::make('vessel_name')->required()->maxLength(80),
            Forms\Components\Select::make('commodity')->required()
                ->options(collect(Commodity::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
            Forms\Components\TextInput::make('bl_quantity_kg')->label('Bill of lading quantity (kg)')
                ->numeric()->integer()->minValue(1)->required(),
            Forms\Components\DateTimePicker::make('eta')->label('ETA')->seconds(false),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Vessel')->columns(4)->schema([
                TextEntry::make('voyage_ref')->label('Voyage'),
                TextEntry::make('vessel_name')->label('Vessel'),
                TextEntry::make('commodity')->badge()->formatStateUsing(fn (Commodity $state) => strtoupper($state->value)),
                TextEntry::make('status')->badge()
                    ->formatStateUsing(fn (DischargeStatus $state) => $state->label())
                    ->color(fn (DischargeStatus $state) => $state->color()),
            ]),
            Section::make('Cargo reconciliation')->columns(4)->schema([
                TextEntry::make('bl_quantity_kg')->label('Bill of lading (kg)')->numeric(),
                TextEntry::make('received')->label('Received, gross (kg)')
                    ->state(fn (VesselDischarge $r) => number_format($r->receivedGrossKg())),
                TextEntry::make('loss')->label('Transit loss (kg)')
                    ->state(fn (VesselDischarge $r) => number_format($r->transitLossKg())),
                TextEntry::make('remaining')->label('Still to discharge (kg)')
                    ->state(fn (VesselDischarge $r) => number_format($r->remainingKg())." ({$r->progressPercent()}% received)"),
            ]),
            Section::make('Timeline')->columns(4)->schema([
                TextEntry::make('eta')->label('ETA')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('berthed_at')->label('Berthed')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('started_at')->label('Discharge started')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('completed_at')->label('Closed')->dateTime('d M Y H:i')->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withTotals())
            ->defaultSort('id', 'desc')
            ->poll('15s')
            ->recordUrl(fn (VesselDischarge $r) => static::getUrl('view', ['record' => $r]))
            ->columns([
                Tables\Columns\TextColumn::make('voyage_ref')->label('Voyage')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('vessel_name')->label('Vessel')->searchable(),
                Tables\Columns\TextColumn::make('commodity')->badge()->formatStateUsing(fn (Commodity $state) => strtoupper($state->value)),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (DischargeStatus $state) => $state->label())
                    ->color(fn (DischargeStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('bl_quantity_kg')->label('B/L (kg)')->numeric(),
                Tables\Columns\TextColumn::make('received_gross_kg')->label('Received (kg)')->numeric()->default(0),
                Tables\Columns\TextColumn::make('progress')->label('Progress')
                    ->state(fn (VesselDischarge $r) => $r->progressPercent().'%')->badge()
                    ->color(fn (VesselDischarge $r) => $r->progressPercent() >= 100 ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('eta')->label('ETA')->dateTime('d M H:i')->placeholder('—')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(DischargeStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->recordActions([ViewAction::make(), ActionGroup::make(OperationActions::discharge())]);
    }

    public static function getRelations(): array
    {
        return [MovementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVesselDischarges::route('/'),
            'create' => Pages\CreateVesselDischarge::route('/create'),
            'view' => Pages\ViewVesselDischarge::route('/{record}'),
        ];
    }
}
