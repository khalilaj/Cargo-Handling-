<?php

namespace App\Filament\Resources;

use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Operations\Enums\OrderStatus;
use App\Filament\Resources\DeliveryOrderResource\Pages;
use App\Filament\Resources\RelationManagers\MovementsRelationManager;
use App\Filament\Support\OperationActions;
use App\Models\Customer;
use App\Models\DeliveryOrder;
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

class DeliveryOrderResource extends Resource
{
    protected static ?string $model = DeliveryOrder::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Delivery orders';

    protected static ?string $modelLabel = 'delivery order';

    protected static ?string $recordTitleAttribute = 'order_ref';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('order_ref')->label('Order reference')->disabled()->dehydrated(false)->placeholder('Generated automatically'),
            Forms\Components\Select::make('customer_id')->label('Customer')->required()->searchable()
                ->options(fn () => Customer::where('active', true)->orderBy('name')->pluck('name', 'id'))
                ->helperText('Pick from the customer register (Registers → Customers).'),
            Forms\Components\Select::make('commodity')->required()
                ->options(collect(Commodity::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
            Forms\Components\TextInput::make('ordered_kg')->label('Ordered quantity (kg)')
                ->numeric()->integer()->minValue(1)->required(),
            Forms\Components\DateTimePicker::make('scheduled_at')->label('Scheduled for')->seconds(false),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Order')->columns(4)->schema([
                TextEntry::make('order_ref')->label('Order'),
                TextEntry::make('customer'),
                TextEntry::make('commodity')->badge()->formatStateUsing(fn (Commodity $state) => strtoupper($state->value)),
                TextEntry::make('status')->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => $state->color()),
            ]),
            Section::make('Fulfilment')->columns(3)->schema([
                TextEntry::make('ordered_kg')->label('Ordered (kg)')->numeric(),
                TextEntry::make('delivered')->label('Delivered (kg)')
                    ->state(fn (DeliveryOrder $r) => number_format($r->deliveredKg())),
                TextEntry::make('remaining')->label('Still to load (kg)')
                    ->state(fn (DeliveryOrder $r) => number_format($r->remainingKg())." ({$r->progressPercent()}% delivered)"),
            ]),
            Section::make('Timeline')->columns(3)->schema([
                TextEntry::make('scheduled_at')->label('Scheduled')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('started_at')->label('Loading started')->dateTime('d M Y H:i')->placeholder('—'),
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
            ->recordUrl(fn (DeliveryOrder $r) => static::getUrl('view', ['record' => $r]))
            ->columns([
                Tables\Columns\TextColumn::make('order_ref')->label('Order')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('customer')->searchable(),
                Tables\Columns\TextColumn::make('commodity')->badge()->formatStateUsing(fn (Commodity $state) => strtoupper($state->value)),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('ordered_kg')->label('Ordered (kg)')->numeric(),
                Tables\Columns\TextColumn::make('delivered_kg')->label('Delivered (kg)')->numeric()->default(0),
                Tables\Columns\TextColumn::make('progress')->label('Progress')
                    ->state(fn (DeliveryOrder $r) => $r->progressPercent().'%')->badge()
                    ->color(fn (DeliveryOrder $r) => $r->progressPercent() >= 100 ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('scheduled_at')->label('Scheduled')->dateTime('d M H:i')->placeholder('—')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->recordActions([ViewAction::make(), ActionGroup::make(OperationActions::order())]);
    }

    public static function getRelations(): array
    {
        return [MovementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveryOrders::route('/'),
            'create' => Pages\CreateDeliveryOrder::route('/create'),
            'view' => Pages\ViewDeliveryOrder::route('/{record}'),
        ];
    }
}
