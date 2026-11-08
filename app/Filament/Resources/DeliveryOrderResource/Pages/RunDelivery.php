<?php

namespace App\Filament\Resources\DeliveryOrderResource\Pages;

use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Fleet\Actions\TruckVisitWorkflow;
use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Operations\Actions\AdvanceOrderStatus;
use App\Domain\Operations\Actions\RecordTruckLoad;
use App\Domain\Operations\Enums\OrderStatus;
use App\Filament\Resources\DeliveryOrderResource;
use App\Filament\Support\WizardFields;
use App\Filament\Support\WorkflowSteps;
use App\Models\Driver;
use App\Models\Truck;
use App\Models\TruckVisit;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * One page that takes a delivery order from "open loading" to "complete", with each truck's steps inside it:
 * gate in, weigh in, start loading, load from a tank, weigh out, gate out. Then the next truck, or finish.
 * It only calls the domain Actions the buttons used to call. The state is in the database, so the page always
 * opens on the real current step of the order and of the truck you are working on.
 */
class RunDelivery extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DeliveryOrderResource::class;

    protected string $view = 'filament.pages.run-delivery';

    /** 'complete' while closing the order, 'gate-in' while adding another truck. */
    #[Url(as: 'step')]
    public string $step = '';

    /** The truck being worked on. Empty means the first truck still on site. */
    #[Url(as: 'visit')]
    public ?int $visit = null;

    public ?array $gateInData = [];

    public ?array $weighInData = [];

    public ?array $loadData = [];

    public ?array $weighOutData = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(DeliveryOrderResource::canView($this->record), 403);

        $this->gateInForm->fill();
        $this->weighInForm->fill();
        $this->weighOutForm->fill();
        $this->resetLoadForm();
    }

    public function getTitle(): string
    {
        return 'Run '.$this->record->order_ref;
    }

    public function getBreadcrumb(): string
    {
        return 'Run';
    }

    // ---- what the page shows ------------------------------------------------------------------------

    public function canOperate(): bool
    {
        return auth()->user()?->role->canOperate() ?? false;
    }

    public function panel(): string
    {
        return match ($this->record->status) {
            OrderStatus::Pending => 'open',
            OrderStatus::Loading => $this->step === 'complete' ? 'complete' : 'trucks',
            OrderStatus::Completed => 'done',
            OrderStatus::Cancelled => 'cancelled',
        };
    }

    /** Trucks of this order that are still on site, oldest first. */
    private function onSite()
    {
        return $this->record->visits()->open()->with(['truck', 'driver'])->orderBy('id')->get();
    }

    /** The truck being worked on, or null when it is time to gate one in. */
    private function currentVisit($onSite): ?TruckVisit
    {
        if ($this->step === 'gate-in') {
            return null;
        }

        return $onSite->firstWhere('id', $this->visit) ?? $onSite->first();
    }

    /** Which sub-step of the truck lane to show. */
    public function lane(?TruckVisit $v): string
    {
        return match ($v?->status) {
            null => 'gate-in',
            VisitStatus::Arrived => 'weigh-in',
            VisitStatus::WeighedIn => 'start',
            VisitStatus::Loading => 'load',
            VisitStatus::WeighedOut => 'gate-out',
            default => 'gate-in',
        };
    }

    protected function getViewData(): array
    {
        $o = $this->record;
        $onSite = $this->panel() === 'trucks' || $this->panel() === 'complete' || $this->panel() === 'open' ? $this->onSite() : collect();
        $current = $this->currentVisit($onSite);

        return [
            'flow' => WorkflowSteps::for($o),
            'panel' => $this->panel(),
            'canOperate' => $this->canOperate(),
            'ordered' => $o->ordered_kg,
            'delivered' => $o->deliveredKg(),
            'remaining' => $o->remainingKg(),
            'percent' => min($o->progressPercent(), 100),
            'onSite' => $onSite,
            'current' => $current,
            'lane' => $this->lane($current),
            'visitFlow' => $current ? WorkflowSteps::for($current) : null,
            'loads' => $current ? $current->movements()->with('tank')->latest('occurred_at')->get() : collect(),
            'loadHint' => $current ? WizardFields::loadHint($current) : '',
            'detailsUrl' => DeliveryOrderResource::getUrl('view', ['record' => $o]),
            'listUrl' => DeliveryOrderResource::getUrl(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel_visit')->label('Cancel this truck')->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Use this only if the truck leaves without loading.')
                ->visible(fn () => $this->canOperate() && ($v = $this->currentVisit($this->onSite())) && $v->status->canTransitionTo(VisitStatus::Cancelled))
                ->action(fn () => $this->act(
                    fn () => app(TruckVisitWorkflow::class)->cancel($this->currentVisit($this->onSite())),
                    'Truck visit cancelled',
                )),
            Action::make('cancel_order')->label('Cancel order')->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Stops this order for good. It cannot be cancelled once anything has been loaded; complete it instead.')
                ->visible(fn () => $this->canOperate() && $this->record->status->canTransitionTo(OrderStatus::Cancelled))
                ->action(fn () => $this->act(fn () => app(AdvanceOrderStatus::class)($this->record, OrderStatus::Cancelled), 'Order cancelled')),
        ];
    }

    // ---- the forms ----------------------------------------------------------------------------------

    public function gateInForm(Schema $schema): Schema
    {
        return $schema->statePath('gateInData')->components([
            Forms\Components\Select::make('truck_id')->label('Truck')->required()->searchable()
                ->options(fn () => Truck::where('active', true)->whereDoesntHave('activeVisit')->orderBy('plate')->get()
                    ->mapWithKeys(fn (Truck $t) => [$t->id => $t->plate.' · '.$t->complianceStatus()->label()]))
                ->helperText('Trucks already on site are not listed. Expired documents show a warning but never stop the truck.'),
            Forms\Components\Select::make('driver_id')->label('Driver')->searchable()
                ->options(fn () => Driver::where('active', true)
                    ->whereNotIn('id', TruckVisit::whereNotNull('active_truck_id')->whereNotNull('driver_id')->pluck('driver_id'))
                    ->orderBy('name')->get()->mapWithKeys(fn (Driver $d) => [$d->id => $d->name.' · licence '.strtolower($d->complianceStatus()->label())])),
            Forms\Components\Textarea::make('notes')->rows(2),
        ]);
    }

    public function weighInForm(Schema $schema): Schema
    {
        return $schema->statePath('weighInData')->components([self::weight('Weighbridge reading (kg)', 'The truck is empty.')]);
    }

    public function weighOutForm(Schema $schema): Schema
    {
        return $schema->statePath('weighOutData')->components([
            self::weight('Weighbridge reading (kg)', 'Full truck. It is compared with what was booked out of the tanks.'),
        ]);
    }

    public function loadForm(Schema $schema): Schema
    {
        return $schema->statePath('loadData')->components([
            WizardFields::tank($this->record->commodity),
            WizardFields::quantity(
                fn () => $this->record->remainingKg(),
                fn () => ($v = $this->currentVisit($this->onSite())) ? WizardFields::loadHint($v) : '',
            ),
            WizardFields::when('When'),
            Forms\Components\Hidden::make('idempotency_key'),
        ]);
    }

    private static function weight(string $label, string $help): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('kg')->label($label)->numeric()->integer()->minValue(1)->required()->helperText($help);
    }

    private function resetLoadForm(): void
    {
        $this->loadForm->fill(['occurred_at' => now(), 'idempotency_key' => (string) Str::uuid()]);
    }

    // ---- the steps ----------------------------------------------------------------------------------

    public function openLoading(): void
    {
        $this->act(fn () => app(AdvanceOrderStatus::class)($this->record, OrderStatus::Loading), 'Loading opened');
    }

    public function selectVisit(int $id): void
    {
        $this->visit = $id;
        $this->step = '';
    }

    public function startGateIn(): void
    {
        $this->step = 'gate-in';
    }

    public function backToTrucks(): void
    {
        $this->step = '';
    }

    public function gateIn(): void
    {
        $this->act(function (): ?TruckVisit {
            $d = $this->gateInForm->getState();
            $new = app(TruckVisitWorkflow::class)->gateIn(
                $this->record, Truck::findOrFail($d['truck_id']),
                ($d['driver_id'] ?? null) ? Driver::findOrFail($d['driver_id']) : null, $d['notes'] ?? null,
            );

            if ($new->warnings) {
                Notification::make()->title('Gated in with warnings')->body(implode("\n", $new->warnings))->warning()->persistent()->send();
            }
            $this->visit = $new->id;
            $this->step = '';
            $this->gateInForm->fill();

            return $new;
        }, 'Truck gated in');
    }

    public function weighIn(): void
    {
        $this->visitStep(function (TruckVisit $v) {
            app(TruckVisitWorkflow::class)->weighIn($v, (int) $this->weighInForm->getState()['kg']);
            $this->weighInForm->fill();
        }, 'Weighed in');
    }

    public function startTruckLoading(): void
    {
        $this->visitStep(fn (TruckVisit $v) => app(TruckVisitWorkflow::class)->startLoading($v), 'Loading started');
    }

    public function load(): void
    {
        $this->visitStep(function (TruckVisit $v) {
            $d = $this->loadForm->getState();
            app(RecordTruckLoad::class)(
                $this->record, (int) $d['tank_id'], (int) $d['quantity_kg'],
                Carbon::parse($d['occurred_at']), $d['idempotency_key'], null, $v,
            );
            $this->resetLoadForm();
        }, 'Loaded');
    }

    public function weighOut(): void
    {
        $this->visitStep(function (TruckVisit $v) {
            app(TruckVisitWorkflow::class)->weighOut($v, (int) $this->weighOutForm->getState()['kg']);
            $this->weighOutForm->fill();
        }, 'Weighed out');
    }

    public function gateOut(): void
    {
        $this->visitStep(fn (TruckVisit $v) => app(TruckVisitWorkflow::class)->gateOut($v), 'Truck has left site');
    }

    public function finishOrder(): void
    {
        $this->step = 'complete';
    }

    public function completeOrder(): void
    {
        $this->act(fn () => app(AdvanceOrderStatus::class)($this->record, OrderStatus::Completed), 'Order completed');

        if ($this->record->status === OrderStatus::Completed) {
            $this->step = '';
        }
    }

    /** Run a step on the truck being worked on, and say what comes next for that truck. */
    private function visitStep(Closure $work, string $title): void
    {
        $this->act(function () use ($work) {
            $v = $this->currentVisit($this->onSite());
            abort_if($v === null, 404);
            $work($v);

            return $v;
        }, $title);
    }

    /**
     * Run one step: only people who operate, the domain refuses anything out of order, and whatever happened the
     * record is re-read so the page lands on the real current step. A step may return the truck it worked on,
     * so the pop-up can say what that truck needs next.
     *
     * @param  Closure(): mixed  $work
     */
    private function act(Closure $work, string $title): void
    {
        abort_unless($this->canOperate(), 403);

        try {
            $result = $work();
            $this->record->refresh();
            $about = $result instanceof TruckVisit ? $result->refresh() : $this->record;
            Notification::make()->title($title)->body(WorkflowSteps::for($about)['hint'])->success()->duration(8000)->send();
        } catch (CargoException $e) {
            $this->record->refresh();
            Notification::make()->title('Not done')->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
