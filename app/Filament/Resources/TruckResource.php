<?php

namespace App\Filament\Resources;

use App\Domain\Fleet\Services\ComplianceChecker;
use App\Filament\Resources\RelationManagers\VisitsRelationManager;
use App\Filament\Resources\TruckResource\Pages;
use App\Filament\Support\RegisterActions;
use App\Models\Transporter;
use App\Models\Truck;
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

class TruckResource extends Resource
{
    protected static ?string $model = Truck::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|UnitEnum|null $navigationGroup = 'Registers';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'plate';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('plate')->label('Number plate')->required()->maxLength(20)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule)
                ->dehydrateStateUsing(fn ($state) => strtoupper(trim((string) preg_replace('/\s+/', ' ', (string) $state))))
                ->helperText('"kda  214x" and "KDA 214X" are the same truck.'),
            Forms\Components\Select::make('transporter_id')->label('Transporter')
                ->options(fn () => Transporter::where('active', true)->orderBy('name')->pluck('name', 'id'))->searchable(),
            Forms\Components\TextInput::make('capacity_kg')->label('Maximum payload (kg)')->numeric()->integer()->minValue(1)
                ->helperText('A load above this is refused at the loading bay.'),
            Forms\Components\TextInput::make('tare_kg')->label('Empty weight (kg)')->numeric()->integer()->minValue(1),
            Forms\Components\DatePicker::make('insurance_expiry')->label('Insurance expires'),
            Forms\Components\DatePicker::make('inspection_expiry')->label('Inspection expires'),
            Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $doc = fn (string $field, string $label) => TextEntry::make($field)->label($label)->badge()
            ->state(fn (Truck $record) => ComplianceChecker::statusOf($record->{$field})->label().($record->{$field} ? ' · '.$record->{$field}->format('d M Y') : ''))
            ->color(fn (Truck $record) => ComplianceChecker::statusOf($record->{$field})->color());

        return $schema->components([
            Section::make('Truck')->columns(3)->schema([
                TextEntry::make('plate'),
                TextEntry::make('transporter.name')->label('Transporter')->placeholder('—'),
                TextEntry::make('site')->label('Right now')
                    ->state(fn (Truck $record) => ($v = $record->activeVisit) ? "On site: {$v->ref} ({$v->status->label()})" : 'Not on site'),
                TextEntry::make('capacity_kg')->label('Maximum payload (kg)')->numeric()->placeholder('—'),
                TextEntry::make('tare_kg')->label('Empty weight (kg)')->numeric()->placeholder('—'),
                TextEntry::make('active')->badge()->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')->color(fn ($state) => $state ? 'success' : 'gray'),
            ]),
            Section::make('Documents')->description('Expired documents show a warning at the gate. They never block a truck.')->columns(2)->schema([
                $doc('insurance_expiry', 'Insurance'),
                $doc('inspection_expiry', 'Inspection'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('plate')
            ->modifyQueryUsing(fn ($query) => $query->with(['transporter', 'activeVisit']))
            ->poll('30s')
            ->recordUrl(fn (Truck $record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('plate')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('transporter.name')->label('Transporter')->placeholder('—'),
                Tables\Columns\TextColumn::make('capacity_kg')->label('Payload (kg)')->numeric()->placeholder('—'),
                Tables\Columns\TextColumn::make('documents')->label('Documents')->badge()
                    ->state(fn (Truck $record) => $record->complianceStatus()->label())
                    ->color(fn (Truck $record) => $record->complianceStatus()->color()),
                Tables\Columns\TextColumn::make('site')->label('On site')
                    ->state(fn (Truck $record) => $record->activeVisit?->status->label())->placeholder('—')->badge()->color('warning'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([Tables\Filters\TernaryFilter::make('active')])
            ->recordActions([ViewAction::make(), EditAction::make(), RegisterActions::toggleActive()]);
    }

    public static function getRelations(): array
    {
        return [VisitsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrucks::route('/'),
            'create' => Pages\CreateTruck::route('/create'),
            'view' => Pages\ViewTruck::route('/{record}'),
            'edit' => Pages\EditTruck::route('/{record}/edit'),
        ];
    }
}
