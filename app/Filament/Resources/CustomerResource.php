<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\RelationManagers\CustomerOrdersRelationManager;
use App\Filament\Support\RegisterActions;
use App\Models\Customer;
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

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|UnitEnum|null $navigationGroup = 'Registers';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')->required()->unique(ignoreRecord: true)->maxLength(120),
            Forms\Components\TextInput::make('contact_person')->maxLength(120),
            Forms\Components\TextInput::make('phone')->tel()->maxLength(40),
            Forms\Components\TextInput::make('email')->email()->maxLength(120),
            Forms\Components\Toggle::make('active')->default(true)->helperText('Inactive customers are not offered on new delivery orders.'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Customer')->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('contact_person')->placeholder('—'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('orders_count')->label('Delivery orders')->state(fn (Customer $record) => $record->orders()->count()),
                TextEntry::make('active')->badge()->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')->color(fn ($state) => $state ? 'success' : 'gray'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->withCount('orders'))
            ->recordUrl(fn (Customer $record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('contact_person')->placeholder('—'),
                Tables\Columns\TextColumn::make('phone')->placeholder('—'),
                Tables\Columns\TextColumn::make('orders_count')->label('Orders')->numeric(),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([RegisterActions::statusFilter()])
            ->recordActions([ViewAction::make(), EditAction::make(), ...RegisterActions::archiveActions()]);
    }

    public static function getRelations(): array
    {
        return [CustomerOrdersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
