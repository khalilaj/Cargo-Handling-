<?php

namespace App\Filament\Resources\VesselDischargeResource\Pages;

use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Operations\Actions\AdvanceDischargeStatus;
use App\Domain\Operations\Actions\RecordDischargeIntoTank;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Filament\Resources\VesselDischargeResource;
use App\Filament\Support\Concerns\HasQuantityChips;
use App\Filament\Support\QuantityChips;
use App\Filament\Support\WizardFields;
use App\Filament\Support\WorkflowSteps;
use App\Models\Berth;
use App\Models\Tank;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * One page that walks a voyage through berth, start, pump and complete.
 * It only calls the domain Actions the buttons used to call; every rule still lives there.
 * The state is the voyage's status in the database, so the page always opens on the real current step.
 */
class RunDischarge extends Page
{
    use HasQuantityChips;
    use InteractsWithRecord;

    protected static string $resource = VesselDischargeResource::class;

    protected string $view = 'filament.pages.run-discharge';

    /** 'complete' while a voyage that is still discharging is being closed. */
    #[Url(as: 'step')]
    public string $step = '';

    public ?array $berthData = [];

    public ?array $pumpData = [];

    public ?array $completeData = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(VesselDischargeResource::canView($this->record), 403);

        $this->berthForm->fill();
        $this->completeForm->fill();
        $this->resetPumpForm();
    }

    public function getTitle(): string
    {
        return 'Run '.$this->record->voyage_ref;
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

    /** Supervisors and admins may close a voyage below 99% of the bill of lading, with a reason. */
    public function canCloseEarly(): bool
    {
        return auth()->user()?->role->canApprove() ?? false;
    }

    public function panel(): string
    {
        return match ($this->record->status) {
            DischargeStatus::Scheduled => 'berth',
            DischargeStatus::Berthed => 'start',
            DischargeStatus::Discharging => $this->step === 'complete' ? 'complete' : 'pump',
            DischargeStatus::Completed => 'done',
            DischargeStatus::Cancelled => 'cancelled',
        };
    }

    protected function getViewData(): array
    {
        $d = $this->record;

        return [
            'flow' => WorkflowSteps::for($d),
            'panel' => $this->panel(),
            'canOperate' => $this->canOperate(),
            'canCloseEarly' => $this->canCloseEarly(),
            'fullyIn' => $d->isFullyDischarged(),
            'gross' => $d->receivedGrossKg(),
            'loss' => $d->transitLossKg(),
            'net' => $d->receivedNetKg(),
            'remaining' => $d->remainingKg(),
            'percent' => min($d->progressPercent(), 100),
            'pumps' => $d->movements()->with('tank')->latest('occurred_at')->limit(8)->get(),
            'detailsUrl' => VesselDischargeResource::getUrl('view', ['record' => $d]),
            'listUrl' => VesselDischargeResource::getUrl(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel_voyage')->label('Cancel voyage')->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Stops this voyage for good. Use it only if the ship will not discharge here.')
                ->visible(fn () => $this->canOperate() && $this->record->status->canTransitionTo(DischargeStatus::Cancelled))
                ->action(fn () => $this->act(
                    fn () => app(AdvanceDischargeStatus::class)($this->record, DischargeStatus::Cancelled),
                    'Voyage cancelled',
                )),
        ];
    }

    // ---- the forms ----------------------------------------------------------------------------------

    public function berthForm(Schema $schema): Schema
    {
        return $schema->statePath('berthData')->disabled(fn () => ! $this->canOperate())->components([
            Forms\Components\Select::make('berth_id')->label('Berth')->required()
                ->options(fn () => Berth::where('active', true)->orderBy('code')->get()
                    ->filter(fn (Berth $b) => ($o = $b->occupant()) === null || $o->id === $this->record->id)
                    ->mapWithKeys(fn (Berth $b) => [$b->id => "{$b->code} · {$b->name}"]))
                ->default($this->record->berth_id)
                ->helperText('Only free berths are listed (Registers, Berths).'),
        ]);
    }

    public function pumpForm(Schema $schema): Schema
    {
        return $schema->statePath('pumpData')->disabled(fn () => ! $this->canOperate())->components([
            WizardFields::tank($this->record->commodity),
            WizardFields::quantity(
                fn () => $this->record->remainingKg(),
                fn () => 'Remaining on the bill of lading: '.number_format($this->record->remainingKg()).' kg',
                live: true,
            ),
            $this->quantityChipsComponent(),
            Text::make(fn (Get $get): string => WizardFields::lossPreview((int) $get('quantity_kg')))->color('warning'),
            WizardFields::when(),
            Forms\Components\Hidden::make('idempotency_key'),
        ]);
    }

    public function completeForm(Schema $schema): Schema
    {
        // Everyone sees the reason box. Only roles that may close a voyage early can fill it in.
        return $schema->statePath('completeData')->disabled(fn () => ! $this->canCloseEarly())->components([
            Forms\Components\Textarea::make('shortfall_reason')->label('Why is it being closed early?')->required()->maxLength(500)
                ->helperText(fn () => "Only {$this->record->progressPercent()}% of the bill of lading has been received ({$this->record->remainingKg()} kg still on the ship)."),
        ]);
    }

    /** Quarter, half and everything that fits, from what is left on the ship and the room in the chosen tank. */
    public function quantityChips(): array
    {
        $limits = ['All remaining' => $this->record->remainingKg()];

        if ($tank = Tank::find($this->pumpData['tank_id'] ?? null)) {
            $limits['Fill the tank'] = QuantityChips::grossThatFits($tank->capacity_kg - $tank->current_volume_kg);
        }

        return QuantityChips::from($limits);
    }

    protected function quantityStatePath(): string
    {
        return 'pumpData';
    }

    private function resetPumpForm(): void
    {
        $this->pumpForm->fill(['occurred_at' => now(), 'idempotency_key' => (string) Str::uuid()]);
    }

    // ---- the steps ----------------------------------------------------------------------------------

    public function berth(): void
    {
        $this->act(function () {
            $data = $this->berthForm->getState();
            app(AdvanceDischargeStatus::class)($this->record, DischargeStatus::Berthed, Berth::findOrFail($data['berth_id']));
        }, 'Ship berthed');
    }

    public function start(): void
    {
        $this->act(fn () => app(AdvanceDischargeStatus::class)($this->record, DischargeStatus::Discharging), 'Discharge started');
    }

    public function pump(): void
    {
        $this->act(function () {
            $d = $this->pumpForm->getState();
            app(RecordDischargeIntoTank::class)(
                $this->record, (int) $d['tank_id'], (int) $d['quantity_kg'],
                Carbon::parse($d['occurred_at']), $d['idempotency_key'],
            );
            $this->resetPumpForm();
        }, 'Cargo pumped into tank');
    }

    public function finishPumping(): void
    {
        $this->step = 'complete';
    }

    public function backToPumping(): void
    {
        $this->step = '';
    }

    public function complete(): void
    {
        $early = ! $this->record->isFullyDischarged() && $this->canCloseEarly();

        $this->act(function () use ($early) {
            $reason = $early ? $this->completeForm->getState()['shortfall_reason'] : null;
            app(AdvanceDischargeStatus::class)($this->record, DischargeStatus::Completed, null, $reason, auth()->user());
        }, 'Voyage completed');

        if ($this->record->status === DischargeStatus::Completed) {
            $this->step = '';
        }
    }

    /**
     * Run one step: only people who operate, domain rules refuse anything out of order, and whatever
     * happened the record is re-read so the page lands on the real current step.
     *
     * @param  Closure(): mixed  $work
     */
    private function act(Closure $work, string $title): void
    {
        abort_unless($this->canOperate(), 403);

        try {
            $work();
            $this->record->refresh();
            Notification::make()->title($title)->body(WorkflowSteps::for($this->record)['hint'])->success()->duration(8000)->send();
        } catch (CargoException $e) {
            $this->record->refresh();
            Notification::make()->title('Not done')->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
