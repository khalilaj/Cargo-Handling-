<?php

namespace App\Filament\Resources;

use App\Domain\Cargo\Enums\MovementType;
use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Corrections\Actions\RequestCorrection;
use App\Domain\Corrections\Enums\CorrectionStatus;
use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Filament\Resources\CargoMovementResource\Pages;
use App\Models\AuditLog;
use App\Models\CargoMovement;
use App\Support\KenyaTime;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            ViewEntry::make('story')->hiddenLabel()->columnSpanFull()->view('filament.components.movement-journey')
                ->viewData(fn (CargoMovement $record) => ['story' => static::story($record)]),
            Section::make('The figures')->columns(3)->schema([
                TextEntry::make('gross_kg')->label('Gross (kg)')->numeric(),
                TextEntry::make('loss_kg')->label('Lost in transit (kg)')->numeric(),
                TextEntry::make('net_kg')->label('Net (kg)')->numeric(),
                TextEntry::make('occurred_at')->label('When')->dateTime('d M Y H:i')->timezone(KenyaTime::ZONE),
                TextEntry::make('recorded_by')->label('Recorded by')->placeholder('Not recorded')
                    ->state(fn (CargoMovement $record) => AuditLog::where('subject_type', 'CargoMovement')->where('subject_id', $record->id)
                        ->where('event', 'cargo_movement.created')->value('actor_name')),
                TextEntry::make('correction_state')->label('Status')->badge()->placeholder('Normal')
                    ->state(fn (CargoMovement $m) => static::correctionState($m))
                    ->color(fn (?string $state) => static::correctionColor($state)),
            ]),
        ]);
    }

    private static function correctionState(CargoMovement $m): ?string
    {
        return match (true) {
            $m->isReversal() => 'Reversal',
            $m->isReversed() => 'Reversed',
            $m->hasPendingCorrection() => 'Correction pending',
            default => null,
        };
    }

    private static function correctionColor(?string $state): string
    {
        return match ($state) {
            'Reversed' => 'danger', 'Correction pending' => 'warning', default => 'gray',
        };
    }

    private static function kg(int $kg): string
    {
        return number_format($kg).' kg';
    }

    private static function tonnes(int $kg): string
    {
        return number_format($kg / 1000, 1).' t';
    }

    private static function when(CargoMovement $m): string
    {
        return KenyaTime::of($m->occurred_at)->format('d M Y H:i');
    }

    private static function movementUrl(CargoMovement $m): string
    {
        return static::getUrl('view', ['record' => $m->id]);
    }

    /**
     * The story of one ledger row in plain words: steps (with links), one "where it is now" sentence and notes.
     *
     * @return array{steps: list<array>, now: string, notes: list<string>}
     */
    public static function story(CargoMovement $m): array
    {
        $m->loadMissing(['tank', 'vesselDischarge', 'deliveryOrder', 'truckVisit.truck', 'reverses', 'reversal']);
        $tank = $m->tank;
        $holds = "{$tank->code}, which holds ".static::tonnes($tank->current_volume_kg).' of '.static::tonnes($tank->capacity_kg);
        $notes = $m->hasPendingCorrection() ? ['A correction has been requested and is waiting for a supervisor. Nothing changes until it is decided.'] : [];

        if ($m->isReversal()) {
            return static::reversalStory($m, $holds, $notes);
        }

        if ($m->type->isInbound()) {
            $steps = static::inboundSteps($m);
            $now = "In tank {$holds}.";
        } else {
            [$steps, $now] = static::outboundSteps($m);
        }

        if ($reversal = $m->reversal) {
            $inbound = $m->type->isInbound();
            $steps[] = [
                'title' => 'Cancelled by a correction',
                'detail' => static::when($reversal).'. '.($inbound ? 'The cargo was taken back out of the tank.' : 'The cargo was put back into the tank.'),
                'links' => [["Open the reversal #{$reversal->id}", static::movementUrl($reversal)]], 'state' => 'warn',
            ];
            $now = ($inbound ? 'Cancelled by a correction, so the cargo is no longer in the tank' : 'Cancelled by a correction, so the cargo is back in the tank').". Tank {$holds}.";
        }

        return compact('steps', 'now', 'notes');
    }

    /** @return array{steps: list<array>, now: string, notes: list<string>} */
    private static function reversalStory(CargoMovement $m, string $holds, array $notes): array
    {
        $original = $m->reverses;
        $inbound = $original->type->isInbound();
        $tank = $m->tank;
        $steps = [
            [
                'title' => "This entry cancels movement #{$original->id}",
                'detail' => ($inbound ? 'Discharge' : 'Delivery').' of '.static::kg($original->net_kg)." net, tank {$tank->code}, ".static::when($original).'.',
                'links' => [["Open movement #{$original->id}", static::movementUrl($original)]], 'state' => 'done',
            ],
            [
                'title' => $inbound ? "Taken back out of tank {$tank->code}" : "Put back into tank {$tank->code}",
                'detail' => static::when($m).'. '.static::kg($m->net_kg).' net.', 'links' => [], 'state' => 'done',
            ],
        ];
        $now = ($inbound ? 'The discharge was cancelled by a correction' : 'The delivery was cancelled by a correction').". Tank {$holds}.";

        return ['steps' => $steps, 'now' => $now, 'notes' => $notes];
    }

    /** @return list<array> */
    private static function inboundSteps(CargoMovement $m): array
    {
        $tank = $m->tank;
        $voyage = $m->vesselDischarge;
        $first = $voyage
            ? ['title' => 'Arrived by ship', 'detail' => ($voyage->vessel_name ?: 'Vessel').", voyage {$voyage->voyage_ref}.",
                'links' => [["Open voyage {$voyage->voyage_ref}", VesselDischargeResource::getUrl('view', ['record' => $voyage->id])]], 'state' => 'done']
            : ['title' => 'Received', 'detail' => $m->reference ?: 'No voyage on record.', 'links' => [], 'state' => 'done'];

        return [
            $first,
            ['title' => "Pumped into tank {$tank->code}", 'detail' => static::when($m).'. Gross '.static::kg($m->gross_kg).', transit loss '.static::kg($m->loss_kg).', net '.static::kg($m->net_kg).' received.', 'links' => [], 'state' => 'done'],
            ['title' => 'Tank today', 'detail' => "{$tank->code} holds ".static::tonnes($tank->current_volume_kg).' of '.static::tonnes($tank->capacity_kg).'.', 'links' => [], 'state' => 'now'],
        ];
    }

    /** @return array{0: list<array>, 1: string} */
    private static function outboundSteps(CargoMovement $m): array
    {
        $tank = $m->tank;
        $visit = $m->truckVisit;
        $order = $m->deliveryOrder;
        $plate = $visit?->truck?->plate ?? ($m->reference ?: 'a truck');
        $links = array_values(array_filter([
            $visit ? ["Open truck visit {$visit->ref}", TruckVisitResource::getUrl('view', ['record' => $visit->id])] : null,
            $order ? ["Open order {$order->order_ref}", DeliveryOrderResource::getUrl('view', ['record' => $order->id])] : null,
        ]));
        $where = $visit ? static::visitPlace($visit->status) : null;
        $steps = [
            ['title' => "Taken from tank {$tank->code}", 'detail' => 'Tank now '.static::tonnes($tank->current_volume_kg).' of '.static::tonnes($tank->capacity_kg).'.', 'links' => [], 'state' => 'done'],
            ['title' => "Loaded onto truck {$plate}", 'detail' => static::when($m).'. '.static::kg($m->net_kg).($order ? " for order {$order->order_ref} ({$order->customer})" : '').'.', 'links' => $links, 'state' => 'done'],
            ['title' => $visit ? 'The truck now' : 'Delivery', 'detail' => $where ? ucfirst($where).'.' : ($order ? 'Order is '.strtolower($order->status->label()).'.' : 'No truck visit on record.'), 'links' => [], 'state' => 'now'],
        ];

        $customer = $order?->customer ?? 'the customer';
        $now = match (true) {
            $visit?->status === VisitStatus::Departed => "Delivered to {$customer}, truck left the site.",
            $visit?->status === VisitStatus::Cancelled => "Loaded onto truck {$plate}, but the visit was cancelled.",
            $visit !== null => "On truck {$plate}, {$where}.",
            $order?->status === OrderStatus::Completed => "Delivered to {$customer}.",
            default => "Loaded for {$customer}.",
        };

        return [$steps, $now];
    }

    private static function visitPlace(VisitStatus $status): string
    {
        return match ($status) {
            VisitStatus::Arrived => 'still at the gate',
            VisitStatus::WeighedIn => 'still on site, weighed in and waiting to load',
            VisitStatus::Loading => 'still at the loading bay',
            VisitStatus::WeighedOut => 'weighed out, about to leave',
            VisitStatus::Departed => 'left the site',
            VisitStatus::Cancelled => 'visit was cancelled',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (CargoMovement $record) => static::getUrl('view', ['record' => $record]))
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
                    ->state(fn (CargoMovement $m) => static::correctionState($m))
                    ->color(fn (?string $state) => static::correctionColor($state)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(collect(MovementType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])),
                Tables\Filters\SelectFilter::make('tank_id')->label('Tank')->relationship('tank', 'code'),
            ])
            ->recordActions([
                ViewAction::make()->iconButton()->tooltip('View'),
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
        return [
            'index' => Pages\ListCargoMovements::route('/'),
            'view' => Pages\ViewCargoMovement::route('/{record}'),
        ];
    }
}
