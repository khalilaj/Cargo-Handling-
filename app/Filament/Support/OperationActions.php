<?php

namespace App\Filament\Support;

use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Cargo\Services\TransitLossCalculator;
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
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
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
                ->modalDescription('Choose the tank that holds this product, then the quantity. The loss is taken off for you and shown below.')
                ->visible(fn (VesselDischarge $record) => $record->status->isActive())
                ->schema(fn (VesselDischarge $record) => self::movementForm($record->commodity, $record->remainingKg(), 'B/L', showLoss: true))
                ->action(fn (array $data, VesselDischarge $record) => self::run(
                    fn () => app(RecordDischargeIntoTank::class)(
                        $record, (int) $data['tank_id'], (int) $data['quantity_kg'],
                        Carbon::parse($data['occurred_at']), $data['idempotency_key'],
                    ),
                    'Cargo pumped into tank', $record,
                )),
        ];

        // Berthing needs a berth: only free, in-service berths are offered.
        $actions[] = Action::make('to_berthed')
            ->label(DischargeStatus::Berthed->actionLabel())
            ->color('gray')
            ->modalHeading('Assign a berth')
            ->modalDescription('Pick a berth that is free. The ship is then alongside and can start discharging.')
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
                'Discharge is now Berthed', $record,
            ));

        foreach ([DischargeStatus::Discharging, DischargeStatus::Completed, DischargeStatus::Cancelled] as $to) {
            $actions[] = Action::make('to_'.$to->value)
                ->label($to->actionLabel())
                ->color($to === DischargeStatus::Cancelled ? 'danger' : 'gray')
                ->requiresConfirmation()
                ->modalDescription(self::dischargeStepTip($to))
                ->visible(fn (VesselDischarge $record) => $record->status->canTransitionTo($to))
                // Closing early (cargo still on the ship) asks a supervisor for the reason; everyone else is told why not.
                ->schema(fn (VesselDischarge $record) => $to === DischargeStatus::Completed && ! $record->isFullyDischarged() && (auth()->user()?->role->canApprove() ?? false)
                    ? [Forms\Components\Textarea::make('shortfall_reason')->label('Why is it being closed early?')->required()->maxLength(500)
                        ->helperText("Only {$record->progressPercent()}% of the bill of lading has been received ({$record->remainingKg()} kg still on the ship).")]
                    : [])
                ->action(fn (array $data, VesselDischarge $record) => self::run(
                    fn () => app(AdvanceDischargeStatus::class)($record, $to, null, $data['shortfall_reason'] ?? null, auth()->user()),
                    "Discharge is now {$to->label()}", $record,
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
                ->modalDescription('Choose the truck in the loading bay and the tank the oil comes from. Repeat for each truck.')
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
                    'Truck loaded', $record,
                )),
        ];

        foreach ([OrderStatus::Loading, OrderStatus::Completed, OrderStatus::Cancelled] as $to) {
            $actions[] = Action::make('to_'.$to->value)
                ->label($to->actionLabel())
                ->color($to === OrderStatus::Cancelled ? 'danger' : 'gray')
                ->requiresConfirmation()
                ->modalDescription(self::orderStepTip($to))
                ->visible(fn (DeliveryOrder $record) => $record->status->canTransitionTo($to))
                ->action(fn (DeliveryOrder $record) => self::run(
                    fn () => app(AdvanceOrderStatus::class)($record, $to),
                    "Order is now {$to->label()}", $record,
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

    /** @param  bool  $showLoss  inbound vessel pumping only: show the transit loss as the quantity is typed */
    private static function movementForm($commodity, int $remainingKg, string $limitLabel, bool $showLoss = false): array
    {
        $quantity = Forms\Components\TextInput::make('quantity_kg')->label('Quantity (kg)')->numeric()->integer()
            ->minValue(1)->maxValue($remainingKg)->required()
            ->helperText('Remaining on '.$limitLabel.': '.number_format($remainingKg).' kg');

        $fields = [
            Forms\Components\Select::make('tank_id')->label('Tank')->required()->searchable()
                ->options(fn () => Tank::where('commodity', $commodity)->orderBy('code')->get()->mapWithKeys(fn (Tank $t) => [
                    $t->id => sprintf('%s · %s of %s t used', $t->code,
                        number_format($t->current_volume_kg / 1000, 1), number_format($t->capacity_kg / 1000, 1)),
                ]))
                ->helperText('Only tanks that hold this product are listed.'),
            $quantity,
        ];

        if ($showLoss) {
            $quantity->live(onBlur: true);
            $fields[] = Text::make(fn (Get $get): string => self::lossPreview((int) $get('quantity_kg')))->color('warning');
        }

        return [
            ...$fields,
            Forms\Components\DateTimePicker::make('occurred_at')->label('When it happened')->seconds(false)->default(now())->required(),
            Forms\Components\Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
        ];
    }

    /** What the person is about to book, in plain words. The rate and the sums come from the one calculator. */
    private static function lossPreview(int $grossKg): string
    {
        $percent = TransitLossCalculator::LOSS_PER_MILLE / 10;

        if ($grossKg <= 0) {
            return "Transit loss: {$percent}% of what you pump is taken off automatically. Enter a quantity to see the figures.";
        }

        $loss = app(TransitLossCalculator::class)->lossFor($grossKg);

        return sprintf('Pumping %s kg: %s kg is lost in transit (%s%%), so %s kg goes into the tank.',
            number_format($grossKg), number_format($loss), $percent, number_format($grossKg - $loss));
    }

    /** One plain sentence for the pop-up asking to confirm a voyage step. */
    private static function dischargeStepTip(DischargeStatus $to): string
    {
        return match ($to) {
            DischargeStatus::Discharging => 'Starts pumping. After this, press "Pump into tank" for each tank you fill.',
            DischargeStatus::Completed => 'Closes the voyage. At least 99% of the bill of lading must be received. A supervisor can close earlier with a written reason.',
            DischargeStatus::Cancelled => 'Stops this voyage for good. Use it only if the ship will not discharge here.',
            default => '',
        };
    }

    /** One plain sentence for the pop-up asking to confirm an order step. */
    private static function orderStepTip(OrderStatus $to): string
    {
        return match ($to) {
            OrderStatus::Loading => 'Opens the order for loading. Trucks you gate in can then be loaded against it.',
            OrderStatus::Completed => 'Closes the order. Do this once the full quantity has been delivered.',
            OrderStatus::Cancelled => 'Stops this order for good. Anything already loaded stays in the ledger.',
            default => '',
        };
    }

    /** After a step: say what happened and what to do next, in a pop-up that stays long enough to read. */
    private static function run(Closure $work, string $successTitle, ?Model $record = null): void
    {
        try {
            $work();
            $notice = Notification::make()->title($successTitle)->success();
            if ($record) {
                $notice->body(WorkflowSteps::for($record->refresh())['hint'])->duration(10000);
            }
            $notice->send();
        } catch (CargoException $e) {
            Notification::make()->title('Rejected')->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
