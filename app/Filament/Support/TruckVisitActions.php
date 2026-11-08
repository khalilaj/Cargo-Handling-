<?php

namespace App\Filament\Support;

use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Fleet\Actions\TruckVisitWorkflow;
use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Operations\Actions\RecordTruckLoad;
use App\Domain\Operations\Enums\OrderStatus;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\TruckVisit;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Filament buttons for a truck's visit. All rules live in TruckVisitWorkflow / RecordTruckLoad. */
class TruckVisitActions
{
    private static function mayOperate(): bool
    {
        return auth()->user()?->role->canOperate() ?? false;
    }

    public static function gateIn(): Action
    {
        return Action::make('gate_in')
            ->label('Gate in truck')->icon('heroicon-o-arrow-right-end-on-rectangle')->color('primary')
            ->authorize(fn () => self::mayOperate())
            ->modalHeading('Gate in a truck')
            ->modalDescription('Expired documents show a warning but never stop the truck.')
            ->schema([
                Forms\Components\Select::make('order_id')->label('Delivery order')->required()->searchable()
                    ->options(fn () => DeliveryOrder::whereIn('status', [OrderStatus::Pending->value, OrderStatus::Loading->value])
                        ->orderBy('order_ref')->get()->mapWithKeys(fn (DeliveryOrder $o) => [$o->id => "{$o->order_ref} · {$o->customer} · ".strtoupper($o->commodity->value)])),
                Forms\Components\Select::make('truck_id')->label('Truck')->required()->searchable()
                    ->options(fn () => Truck::where('active', true)->whereDoesntHave('activeVisit')->orderBy('plate')->get()
                        ->mapWithKeys(fn (Truck $t) => [$t->id => $t->plate.' · '.$t->complianceStatus()->label()]))
                    ->helperText('Trucks already on site are not listed.'),
                Forms\Components\Select::make('driver_id')->label('Driver')->searchable()
                    ->options(fn () => Driver::where('active', true)
                        ->whereNotIn('id', TruckVisit::whereNotNull('active_truck_id')->whereNotNull('driver_id')->pluck('driver_id'))
                        ->orderBy('name')->get()->mapWithKeys(fn (Driver $d) => [$d->id => $d->name.' · licence '.strtolower($d->complianceStatus()->label())])),
                Forms\Components\Textarea::make('notes')->rows(2),
            ])
            ->action(function (array $data) {
                self::run(function () use ($data) {
                    $visit = app(TruckVisitWorkflow::class)->gateIn(
                        DeliveryOrder::findOrFail($data['order_id']), Truck::findOrFail($data['truck_id']),
                        ($data['driver_id'] ?? null) ? Driver::findOrFail($data['driver_id']) : null, $data['notes'] ?? null,
                    );
                    Notification::make()->title("{$visit->ref} gated in")->body(WorkflowSteps::for($visit)['hint'])->duration(10000)->success()->send();

                    if ($visit->warnings) {
                        Notification::make()->title('Gated in with warnings')->body(implode("\n", $visit->warnings))->warning()->persistent()->send();
                    }
                });
            });
    }

    /** @return list<Action> */
    public static function forVisit(): array
    {
        $flow = fn () => app(TruckVisitWorkflow::class);
        $at = fn (VisitStatus ...$s) => fn (TruckVisit $record) => in_array($record->status, $s, true);

        $weight = fn (string $label, string $help) => [
            Forms\Components\TextInput::make('kg')->label($label)->numeric()->integer()->minValue(1)->required()->helperText($help),
        ];

        $actions = [
            Action::make('weigh_in')->label('Weigh in')->icon('heroicon-o-scale')->color('primary')
                ->visible($at(VisitStatus::Arrived))
                ->modalHeading('Empty weight on arrival')
                ->modalDescription('Weigh the empty truck on the weighbridge and enter the reading. Loading comes next.')
                ->schema($weight('Weighbridge reading (kg)', 'The truck is empty.'))
                ->action(fn (array $data, TruckVisit $record) => self::run(fn () => $flow()->weighIn($record, (int) $data['kg']), 'Weighed in', $record)),

            Action::make('start_loading')->label('Start loading')->icon('heroicon-o-play')->color('primary')
                ->visible($at(VisitStatus::WeighedIn))
                ->requiresConfirmation()
                ->modalDescription('Moves the truck into the loading bay. The delivery order must already be in Loading. Then press "Load from tank" to book the oil.')
                ->action(fn (TruckVisit $record) => self::run(fn () => $flow()->startLoading($record), 'Loading started', $record)),

            self::loadAction(),

            Action::make('weigh_out')->label('Weigh out')->icon('heroicon-o-scale')->color('primary')
                ->visible($at(VisitStatus::Loading))
                ->modalHeading('Loaded weight on departure')
                ->modalDescription('Weigh the loaded truck. The reading is compared with what you booked out of the tanks.')
                ->schema($weight('Weighbridge reading (kg)', 'Full truck. It is compared with what was booked out of the tank.'))
                ->action(fn (array $data, TruckVisit $record) => self::run(fn () => $flow()->weighOut($record, (int) $data['kg']), 'Weighed out', $record)),

            Action::make('gate_out')->label('Gate out')->icon('heroicon-o-arrow-right-start-on-rectangle')->color('success')
                ->visible($at(VisitStatus::WeighedOut))
                ->requiresConfirmation()
                ->modalDescription('Releases the truck from the site. This is the last step of the visit.')
                ->action(fn (TruckVisit $record) => self::run(fn () => $flow()->gateOut($record), 'Truck has left site', $record)),

            Action::make('cancel_visit')->label('Cancel visit')->icon('heroicon-o-x-circle')->color('danger')
                ->visible($at(VisitStatus::Arrived, VisitStatus::WeighedIn))
                ->requiresConfirmation()
                ->modalDescription('Use this only if the truck leaves without loading.')
                ->action(fn (TruckVisit $record) => self::run(fn () => $flow()->cancel($record), 'Visit cancelled', $record)),
        ];

        return array_map(fn (Action $a) => $a->authorize(fn () => self::mayOperate()), $actions);
    }

    /** Book cargo from a tank onto this truck. The payload and order limits are enforced by the domain. */
    public static function loadAction(): Action
    {
        return Action::make('load_visit')->label('Load from tank')->icon('heroicon-o-arrow-down-tray')->color('primary')
            ->visible(fn (TruckVisit $record) => $record->status === VisitStatus::Loading)
            ->modalHeading(fn (TruckVisit $record) => "Load {$record->truck->plate} from a tank")
            ->modalDescription('Choose the tank the oil comes from and the quantity. Repeat for each tank, then press "Weigh out".')
            ->schema(fn (TruckVisit $record) => [
                Forms\Components\Select::make('tank_id')->label('Tank')->required()->searchable()
                    ->options(fn () => Tank::where('commodity', $record->order->commodity)->orderBy('code')->get()
                        ->mapWithKeys(fn (Tank $t) => [$t->id => sprintf('%s · %s kg in tank', $t->code, number_format($t->current_volume_kg))])),
                Forms\Components\TextInput::make('quantity_kg')->label('Quantity (kg)')->numeric()->integer()->minValue(1)->required()
                    ->helperText(self::loadHint($record)),
                Forms\Components\DateTimePicker::make('occurred_at')->label('When')->seconds(false)->default(now())->required(),
                Forms\Components\Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
            ])
            ->action(function (array $data, TruckVisit $record) {
                self::run(fn () => app(RecordTruckLoad::class)(
                    $record->order, (int) $data['tank_id'], (int) $data['quantity_kg'],
                    Carbon::parse($data['occurred_at']), $data['idempotency_key'], null, $record,
                ), 'Loaded', $record);
            });
    }

    public static function loadHint(TruckVisit $v): string
    {
        $room = $v->truck->capacity_kg ? number_format(max($v->truck->capacity_kg - $v->loadedKg(), 0)).' kg of room left on the truck' : 'No payload recorded for this truck';

        return $room.'; '.number_format($v->order->remainingKg()).' kg still to deliver on the order.';
    }

    private static function run(Closure $work, ?string $successTitle = null, ?TruckVisit $record = null): void
    {
        try {
            $work();
            $record?->refresh();
            if ($successTitle) {
                $notice = Notification::make()->title($successTitle)->success();
                if ($record) {
                    $notice->body(WorkflowSteps::for($record)['hint'])->duration(10000);
                }
                $notice->send();
            }
        } catch (CargoException $e) {
            Notification::make()->title('Rejected')->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
