<?php

namespace App\Filament\Resources;

use App\Domain\Fleet\Enums\VisitStatus;
use App\Filament\Resources\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\TruckVisitResource\Pages;
use App\Filament\Support\TruckVisitActions;
use App\Models\TruckVisit;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class TruckVisitResource extends Resource
{
    protected static ?string $model = TruckVisit::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Truck visits';

    protected static ?string $modelLabel = 'truck visit';

    public static function canCreate(): bool
    {
        return false;
    }          // visits start with the "Gate in truck" button

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = TruckVisit::whereNotNull('active_truck_id')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        $dt = fn (string $f, string $label) => TextEntry::make($f)->label($label)->dateTime('d M Y H:i')->placeholder('—');

        return $schema->components([
            Section::make('Visit')->columns(4)->schema([
                TextEntry::make('ref')->label('Visit'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (VisitStatus $state) => $state->label())->color(fn (VisitStatus $state) => $state->color()),
                TextEntry::make('truck.plate')->label('Truck')
                    ->url(fn (TruckVisit $record) => auth()->user()?->can('view', $record->truck) ? TruckResource::getUrl('view', ['record' => $record->truck_id]) : null),
                TextEntry::make('driver.name')->label('Driver')->placeholder('Not recorded'),
                TextEntry::make('order.order_ref')->label('Order')
                    ->url(fn (TruckVisit $record) => DeliveryOrderResource::getUrl('view', ['record' => $record->delivery_order_id])),
                TextEntry::make('order.customer')->label('Customer'),
            ]),
            Section::make('Warnings')->description('Warnings never block a truck; they are recorded for the supervisor.')
                ->visible(fn (TruckVisit $record) => ! empty($record->warnings))
                ->schema([TextEntry::make('warnings')->hiddenLabel()->listWithLineBreaks()->bulleted()->color('warning')]),
            Section::make('Timeline')->columns(5)->schema([
                $dt('gate_in_at', 'Gate in'), $dt('weighed_in_at', 'Weighed in'), $dt('loading_started_at', 'Loading started'),
                $dt('weighed_out_at', 'Weighed out'), $dt('gate_out_at', 'Gate out'),
            ]),
            Section::make('Weights (kg)')->columns(5)->schema([
                TextEntry::make('weigh_in_kg')->label('Empty (in)')->numeric()->placeholder('—'),
                TextEntry::make('weigh_out_kg')->label('Loaded (out)')->numeric()->placeholder('—'),
                TextEntry::make('net')->label('Weighbridge net')->state(fn (TruckVisit $record) => ($n = $record->weighbridgeNetKg()) === null ? '—' : number_format($n)),
                TextEntry::make('booked')->label('Booked out of tanks')->state(fn (TruckVisit $record) => number_format($record->loadedKg())),
                TextEntry::make('variance')->label('Scale vs ledger')
                    ->state(fn (TruckVisit $record) => ($v = $record->varianceKg()) === null ? '—' : (($v > 0 ? '+' : '').number_format($v))),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withTotals()->with(['truck', 'driver', 'order']))
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->recordUrl(fn (TruckVisit $record) => static::getUrl('view', ['record' => $record]))
            ->filters([
                Tables\Filters\Filter::make('on_site')->label('On site now')->default()->toggle()
                    ->query(fn ($query) => $query->whereNotNull('active_truck_id')),
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(VisitStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('ref')->label('Visit')->weight('bold'),
                Tables\Columns\TextColumn::make('truck.plate')->label('Truck')->searchable(),
                Tables\Columns\TextColumn::make('driver.name')->label('Driver')->placeholder('—'),
                Tables\Columns\TextColumn::make('order.order_ref')->label('Order'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (VisitStatus $state) => $state->label())->color(fn (VisitStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('loaded_kg')->label('Loaded (kg)')->numeric()->default(0),
                Tables\Columns\TextColumn::make('warning_count')->label('Warnings')->badge()
                    ->state(fn (TruckVisit $record) => count($record->warnings ?? []) ?: null)
                    ->color('warning')->tooltip(fn (TruckVisit $record) => implode("\n", $record->warnings ?? [])),
                Tables\Columns\TextColumn::make('gate_in_at')->label('Gate in')->dateTime('d M H:i')->sortable(),
            ])
            ->recordActions([ViewAction::make(), ActionGroup::make(TruckVisitActions::forVisit())]);
    }

    public static function getRelations(): array
    {
        return [MovementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTruckVisits::route('/'),
            'view' => Pages\ViewTruckVisit::route('/{record}'),
        ];
    }
}
