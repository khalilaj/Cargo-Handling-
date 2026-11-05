<?php

namespace App\Filament\Support;

use App\Domain\Cargo\Actions\RecordTruckDelivery;
use App\Domain\Cargo\Actions\RecordVesselDischarge;
use App\Domain\Cargo\Exceptions\CargoException;
use App\Models\Tank;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Shared modal form + handler for the two operator buttons. */
class MovementForm
{
    public static function schema(string $partyLabel): array
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
                ->required()->searchable(),
            Forms\Components\TextInput::make('quantity_kg')
                ->label('Quantity (kg, gross)')->numeric()->integer()->minValue(1)->required(),
            Forms\Components\DateTimePicker::make('occurred_at')
                ->label('When it happened')->seconds(false)->default(now())->required(),
            Forms\Components\TextInput::make('reference')->label($partyLabel)->maxLength(120),
            // Same value is resubmitted on a double click, so it can never apply twice.
            Forms\Components\Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
        ];
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
