<?php

namespace App\Filament\Support;

use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Operations\Actions\AdvanceDischargeStatus;
use App\Domain\Operations\Actions\AdvanceOrderStatus;
use App\Domain\Operations\Actions\RecordDischargeIntoTank;
use App\Domain\Operations\Actions\RecordTruckLoad;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Models\Berth;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\TruckVisit;
use App\Models\VesselDischarge;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Filament buttons for the operation lifecycles. All rules live in the domain Actions. */
class OperationActions
{
    /** @return list<Action> */
    public static function discharge(): array
    {
        $actions = [
            Action::make('pump')
                ->label('Pump into tank')->icon('heroicon-o-arrow-down-tray')->color('primary')
                ->modalHeading('Pump cargo into a shore tank (0.5% transit loss applies)')
                ->visible(fn (VesselDischarge $record) => $record->status->isActive())
                ->schema(fn (VesselDischarge $record) => self::movementForm($record->commodity, $record->remainingKg(), 'B/L'))
                ->action(fn (array $data, VesselDischarge $record) => self::run(
                    fn () => app(RecordDischargeIntoTank::class)(
                        $record, (int) $data['tank_id'], (int) $data['quantity_kg'],
                        Carbon::parse($data['occurred_at']), $data['idempotency_key'],
                    ),
                    'Cargo pumped into tank',
                )),
        ];

        // Berthing needs a berth: only free, in-service berths are offered.
        $actions[] = Action::make('to_berthed')
            ->label(DischargeStatus::Berthed->actionLabel())
            ->color('gray')
            ->modalHeading('Assign a berth')
            ->visible(fn (VesselDischarge $record) => $record->status->canTransitionTo(DischargeStatus::Berthed))
            ->schema(fn (VesselDischarge $record) => [
                Forms\Components\Select::make('berth_id')->label('Berth')->required()
                    ->options(fn () => Berth::where('active', true)->orderBy('code')->get()
                        ->filter(fn (Berth $b) => ($o = $b->occupant()) === null || $o->id === $record->id)
                        ->mapWithKeys(fn (Berth $b) => [$b->id => "{$b->code} · {$b->name}"]))
                    ->default($record->berth_id)
                    ->helperText('Only free berths are listed (Registers → Berths).'),
            ])
            ->action(fn (array $data, VesselDischarge $record) => self::run(
                fn () => app(AdvanceDischargeStatus::class)($record, DischargeStatus::Berthed, Berth::findOrFail($data['berth_id'])),
                'Discharge is now Berthed',
            ));

        foreach ([DischargeStatus::Discharging, DischargeStatus::Completed, DischargeStatus::Cancelled] as $to) {
            $actions[] = Action::make('to_'.$to->value)
                ->label($to->actionLabel())
                ->color($to === DischargeStatus::Cancelled ? 'danger' : 'gray')
                ->requiresConfirmation()
                ->visible(fn (VesselDischarge $record) => $record->status->canTransitionTo($to))
                // Closing early (cargo still on the ship) asks a supervisor for the reason; everyone else is told why not.
                ->schema(fn (VesselDischarge $record) => $to === DischargeStatus::Completed && ! $record->isFullyDischarged() && (auth()->user()?->role->canApprove() ?? false)
                    ? [Forms\Components\Textarea::make('shortfall_reason')->label('Why is it being closed early?')->required()->maxLength(500)
                        ->helperText("Only {$record->progressPercent()}% of the bill of lading has been received ({$record->remainingKg()} kg still on the ship).")]
                    : [])
                ->action(fn (array $data, VesselDischarge $record) => self::run(
                    fn () => app(AdvanceDischargeStatus::class)($record, $to, null, $data['shortfall_reason'] ?? null, auth()->user()),
                    "Discharge is now {$to->label()}",
                ));
        }

        return self::refreshingRecord($actions);
    }

    /** @return list<Action> */
    public static function order(): array
    {
        $actions = [
            Action::make('load')
                ->label('Load truck')->icon('heroicon-o-truck')->color('primary')
                ->modalHeading('Load a truck from a tank')
                ->visible(fn (DeliveryOrder $record) => $record->status->isActive())
                ->schema(fn (DeliveryOrder $record) => [
                    Forms\Components\Select::make('visit_id')->label('Truck in the loading bay')->required()
                        ->options(fn () => $record->visits()->where('status', VisitStatus::Loading->value)->with('truck')->get()
                            ->mapWithKeys(fn (TruckVisit $v) => [$v->id => sprintf('%s · %s (%s%s kg loaded)', $v->ref, $v->truck->plate,
                                number_format($v->loadedKg()), $v->truck->capacity_kg ? ' of '.number_format($v->truck->capacity_kg) : '')]))
                        ->helperText('Gate the truck in and start loading first (Operations → Truck visits).'),
                    ...self::movementForm($record->commodity, $record->remainingKg(), 'order'),
                ])
                ->action(fn (array $data, DeliveryOrder $record) => self::run(
                    fn () => app(RecordTruckLoad::class)(
                        $record, (int) $data['tank_id'], (int) $data['quantity_kg'],
                        Carbon::parse($data['occurred_at']), $data['idempotency_key'], null, TruckVisit::findOrFail($data['visit_id']),
                    ),
                    'Truck loaded',
                )),
        ];

        foreach ([OrderStatus::Loading, OrderStatus::Completed, OrderStatus::Cancelled] as $to) {
            $actions[] = Action::make('to_'.$to->value)
                ->label($to->actionLabel())
                ->color($to === OrderStatus::Cancelled ? 'danger' : 'gray')
                ->requiresConfirmation()
                ->visible(fn (DeliveryOrder $record) => $record->status->canTransitionTo($to))
                ->action(fn (DeliveryOrder $record) => self::run(
                    fn () => app(AdvanceOrderStatus::class)($record, $to),
                    "Order is now {$to->label()}",
                ));
        }

        return self::refreshingRecord($actions);
    }

    /**
     * The domain actions re-read and save their own copy of the row, so the page's
     * in-memory record goes stale. Refreshing it makes the buttons and totals update at once.
     *
     * @param  list<Action>  $actions
     * @return list<Action>
     */
    private static function refreshingRecord(array $actions): array
    {
        return array_map(
            fn (Action $a) => $a
                ->authorize(fn () => auth()->user()?->role->canOperate() ?? false)   // hidden AND blocked for read-only roles
                ->after(fn ($record) => $record->refresh()),
            $actions,
        );
    }

    private static function movementForm($commodity, int $remainingKg, string $limitLabel): array
    {
        return [
            Forms\Components\Select::make('tank_id')->label('Tank')->required()->searchable()
                ->options(fn () => Tank::where('commodity', $commodity)->orderBy('code')->get()->mapWithKeys(fn (Tank $t) => [
                    $t->id => sprintf('%s · %s of %s t used', $t->code,
                        number_format($t->current_volume_kg / 1000, 1), number_format($t->capacity_kg / 1000, 1)),
                ]))
                ->helperText('Only tanks that hold this product are listed.'),
            Forms\Components\TextInput::make('quantity_kg')->label('Quantity (kg)')->numeric()->integer()
                ->minValue(1)->maxValue($remainingKg)->required()
                ->helperText('Remaining on '.$limitLabel.': '.number_format($remainingKg).' kg'),
            Forms\Components\DateTimePicker::make('occurred_at')->label('When it happened')->seconds(false)->default(now())->required(),
            Forms\Components\Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
        ];
    }

    private static function run(Closure $work, string $successTitle): void
    {
        try {
            $work();
            Notification::make()->title($successTitle)->success()->send();
        } catch (CargoException $e) {
            Notification::make()->title('Rejected')->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
