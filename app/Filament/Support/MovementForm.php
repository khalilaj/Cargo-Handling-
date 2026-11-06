<?php

namespace App\Filament\Support;

use App\Domain\Cargo\Actions\RecordTruckDelivery;
use App\Domain\Cargo\Actions\RecordVesselDischarge;
use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\TruckVisit;
use App\Models\Vessel;
use App\Models\VesselDischarge;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Shared modal form + handler for the two operator buttons. */
class MovementForm
{
    /** @param 'vessel'|'truck' $party which register the vessel / truck is picked from */
    public static function schema(string $party): array
    {
        return [
            Forms\Components\Select::make('tank_id')
                ->label('Tank')
                ->options(fn () => Tank::orderBy('code')->get()->mapWithKeys(fn (Tank $t) => [
                    $t->id => sprintf(
                        '%s · %s · %s / %s kg',
                        $t->code, $t->commodity->name,
                        number_format($t->current_volume_kg), number_format($t->capacity_kg)
                    ),
                ]))
                ->required()->searchable()
                // Choosing the tank fills in the vessel / truck that is actually working on that product.
                ->live()
                ->afterStateUpdated(fn ($state, $set) => $set('reference', self::suggest($party, (int) $state))),
            Forms\Components\TextInput::make('quantity_kg')
                ->label('Quantity (kg, gross)')->numeric()->integer()->minValue(1)->required(),
            Forms\Components\DateTimePicker::make('occurred_at')
                ->label('When it happened')->seconds(false)->default(now())->required(),
            // Picked from the register, never typed: one ship or truck is one record, spelled one way.
            Forms\Components\Select::make('reference')
                ->label($party === 'vessel' ? 'Vessel' : 'Truck')
                ->options(fn () => $party === 'vessel'
                    ? Vessel::where('active', true)->orderBy('name')->pluck('name', 'name')
                    : Truck::where('active', true)->orderBy('plate')->pluck('plate', 'plate'))
                ->searchable()->required()
                ->helperText($party === 'vessel' ? 'From Registers → Vessels.' : 'From Registers → Trucks.'),
            // Same value is resubmitted on a double click, so it can never apply twice.
            Forms\Components\Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
        ];
    }

    /**
     * The one vessel (discharging) or truck (on site, loading or weighed in) that is working on the tank's product.
     * Several candidates, or none, means no guess: the person picks from the register.
     */
    public static function suggest(string $party, int $tankId): ?string
    {
        $tank = Tank::find($tankId);
        if (! $tank) {
            return null;
        }

        $names = $party === 'vessel'
            ? VesselDischarge::where('status', DischargeStatus::Discharging)->where('commodity', $tank->commodity)
                ->pluck('vessel_name')->unique()
            : TruckVisit::open()->whereIn('status', [VisitStatus::WeighedIn, VisitStatus::Loading])
                ->whereHas('order', fn ($q) => $q->where('commodity', $tank->commodity))
                ->with('truck')->get()->pluck('truck.plate')->unique();

        return $names->count() === 1 ? $names->first() : null;
    }

    public static function handle(string $actionClass, array $data, string $successTitle): void
    {
        try {
            app($actionClass)(
                (int) $data['tank_id'],
                (int) $data['quantity_kg'],
                Carbon::parse($data['occurred_at']),
                $data['idempotency_key'],
                $data['reference'] ?? null,
            );
            Notification::make()->title($successTitle)->success()->send();
        } catch (CargoException $e) {
            Notification::make()->title('Rejected')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public static function discharge(array $data): void
    {
        self::handle(RecordVesselDischarge::class, $data, 'Vessel discharge recorded');
    }

    public static function delivery(array $data): void
    {
        self::handle(RecordTruckDelivery::class, $data, 'Truck delivery recorded');
    }
}
