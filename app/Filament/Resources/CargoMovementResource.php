<?php

namespace App\Filament\Resources;

use App\Domain\Cargo\Enums\MovementType;
use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Corrections\Actions\RequestCorrection;
use App\Domain\Corrections\Enums\CorrectionStatus;
use App\Filament\Resources\CargoMovementResource\Pages;
use App\Models\CargoMovement;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class CargoMovementResource extends Resource
{
    protected static ?string $model = CargoMovement::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Cargo movements';

    /** Movements are an immutable ledger: no create/edit/delete screens. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['tank', 'vesselDischarge', 'deliveryOrder'])->withExists([
                'reversal as is_reversed',
                'correctionRequests as has_pending_correction' => fn ($q) => $q->where('status', CorrectionStatus::Pending->value),
            ]))
            ->defaultSort('occurred_at', 'desc')
            ->poll('10s')
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')->label('When')->dateTime('d M H:i')->sortable(),
                Tables\Columns\TextColumn::make('type')->badge()
                    ->state(fn (CargoMovement $m) => $m->isReversal() ? 'Reversal' : ($m->type->isInbound() ? 'Discharge' : 'Delivery'))
                    ->color(fn (string $state) => match ($state) {
                        'Reversal' => 'gray', 'Discharge' => 'info', default => 'warning'
                    }),
                Tables\Columns\TextColumn::make('tank.code')->label('Tank')->searchable(),
                Tables\Columns\TextColumn::make('operation')->label('Operation')
                    ->state(fn (CargoMovement $m) => $m->vesselDischarge?->voyage_ref ?? $m->deliveryOrder?->order_ref ?? 'Ad hoc'),
                Tables\Columns\TextColumn::make('reference')->label('Vessel / truck')->searchable(),
                Tables\Columns\TextColumn::make('gross_kg')->label('Gross (kg)')->numeric(),
                Tables\Columns\TextColumn::make('loss_kg')->label('Loss (kg)')->numeric(),
                Tables\Columns\TextColumn::make('net_kg')->label('Net (kg)')->numeric(),
                Tables\Columns\TextColumn::make('correction_state')->label('Status')->badge()
                    ->state(fn (CargoMovement $m) => match (true) {
                        $m->isReversal() => 'Reversal',
                        $m->isReversed() => 'Reversed',
                        $m->hasPendingCorrection() => 'Correction pending',
                        default => null,
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'Reversed' => 'danger', 'Reversal' => 'gray', 'Correction pending' => 'warning', default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(collect(MovementType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])),
                Tables\Filters\SelectFilter::make('tank_id')->label('Tank')->relationship('tank', 'code'),
            ])
            ->recordActions([
                Action::make('request_correction')->label('Correct')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                    ->authorize(fn () => auth()->user()?->role->canOperate() ?? false)
                    ->visible(fn (CargoMovement $record) => ! $record->isReversal() && ! $record->isReversed() && ! $record->hasPendingCorrection())
                    ->modalHeading('Request a correction')
                    ->modalDescription('The movement stays on record. If a supervisor approves, a reversal movement cancels it out.')
                    ->schema([Forms\Components\Textarea::make('reason')->label('What was wrong?')->required()->minLength(10)->rows(3)])
                    ->action(function (array $data, CargoMovement $record) {
                        try {
                            app(RequestCorrection::class)($record, auth()->user(), $data['reason']);
                            Notification::make()->title('Correction requested: waiting for a supervisor')->success()->send();
                        } catch (CargoException $e) {
                            Notification::make()->title('Cannot request')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCargoMovements::route('/')];
    }
}
