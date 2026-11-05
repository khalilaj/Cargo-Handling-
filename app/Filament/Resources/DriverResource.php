<?php

namespace App\Filament\Resources;

use App\Domain\Fleet\Services\ComplianceChecker;
use App\Filament\Resources\DriverResource\Pages;
use App\Filament\Resources\RelationManagers\VisitsRelationManager;
use App\Filament\Support\RegisterActions;
use App\Models\Driver;
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

class DriverResource extends Resource
{
    protected static ?string $model = Driver::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|UnitEnum|null $navigationGroup = 'Registers';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')->required()->maxLength(120),
            Forms\Components\TextInput::make('licence_number')->label('Driving licence number')->required()->unique(ignoreRecord: true)->maxLength(40),
            Forms\Components\DatePicker::make('licence_expiry')->label('Licence expires'),
            Forms\Components\TextInput::make('phone')->tel()->maxLength(40),
            Forms\Components\Select::make('transporter_id')->label('Transporter')
                ->options(fn () => Transporter::where('active', true)->orderBy('name')->pluck('name', 'id'))->searchable(),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Driver')->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('licence_number')->label('Licence'),
                TextEntry::make('transporter.name')->label('Transporter')->placeholder('—'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('licence')->label('Licence status')->badge()
                    ->state(fn (Driver $record) => ComplianceChecker::statusOf($record->licence_expiry)->label().($record->licence_expiry ? ' · '.$record->licence_expiry->format('d M Y') : ''))
                    ->color(fn (Driver $record) => ComplianceChecker::statusOf($record->licence_expiry)->color()),
                TextEntry::make('active')->badge()->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')->color(fn ($state) => $state ? 'success' : 'gray'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->with('transporter'))
            ->recordUrl(fn (Driver $record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('licence_number')->label('Licence')->searchable(),
                Tables\Columns\TextColumn::make('licence_expiry')->label('Expires')->date('d M Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('licence_status')->label('Status')->badge()
                    ->state(fn (Driver $record) => $record->complianceStatus()->label())
                    ->color(fn (Driver $record) => $record->complianceStatus()->color()),
                Tables\Columns\TextColumn::make('transporter.name')->label('Transporter')->placeholder('—'),
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
            'index' => Pages\ListDrivers::route('/'),
            'create' => Pages\CreateDriver::route('/create'),
            'view' => Pages\ViewDriver::route('/{record}'),
            'edit' => Pages\EditDriver::route('/{record}/edit'),
        ];
    }
}
