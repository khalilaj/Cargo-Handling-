<?php

namespace App\Filament\Support;

use App\Domain\Cargo\Services\TransitLossCalculator;
use App\Models\Tank;
use App\Models\TruckVisit;
use Closure;
use Filament\Forms;

/** The form fields the discharge and delivery wizards share, so both read and validate the same way. */
class WizardFields
{
    /** Tanks that hold this product, with how full each one is. */
    public static function tank($commodity): Forms\Components\Select
    {
        return Forms\Components\Select::make('tank_id')->label('Tank')->required()->searchable()->live()   // the quick amounts follow the tank
            ->options(fn () => Tank::where('commodity', $commodity)->where('active', true)->orderBy('code')->get()->mapWithKeys(fn (Tank $t) => [
                $t->id => sprintf('%s · %s of %s t used', $t->code,
                    number_format($t->current_volume_kg / 1000, 1), number_format($t->capacity_kg / 1000, 1)),
            ]))
            ->helperText('Only tanks that hold this product are listed.');
    }

    /** @param  int|Closure(): int  $max  the most the domain would accept right now */
    public static function quantity(int|Closure $max, string|Closure $help, bool $live = false): Forms\Components\TextInput
    {
        $field = Forms\Components\TextInput::make('quantity_kg')->label('Quantity (kg)')->numeric()->integer()
            ->minValue(1)->maxValue($max)->required()->helperText($help);

        return $live ? $field->live(onBlur: true) : $field;
    }

    /** Entered and shown in Kenya time; stored in UTC. */
    public static function when(string $label = 'When it happened'): Forms\Components\DateTimePicker
    {
        return Forms\Components\DateTimePicker::make('occurred_at')->label($label)->seconds(false)->required();
    }

    /** How much room the truck has left and how much the order still needs. */
    public static function loadHint(TruckVisit $v): string
    {
        $room = $v->truck->capacity_kg
            ? number_format(max($v->truck->capacity_kg - $v->loadedKg(), 0)).' kg of room left on the truck'
            : 'No payload recorded for this truck';

        return $room.'; '.number_format($v->order->remainingKg()).' kg still to deliver on the order.';
    }

    /** What the person is about to book, in plain words. The rate and the sums come from the one calculator. */
    public static function lossPreview(int $grossKg): string
    {
        $percent = TransitLossCalculator::LOSS_PER_MILLE / 10;

        if ($grossKg <= 0) {
            return "Transit loss: {$percent}% of what you pump is taken off automatically. Enter a quantity to see the figures.";
        }

        $loss = app(TransitLossCalculator::class)->lossFor($grossKg);

        return sprintf('Pumping %s kg: %s kg is lost in transit (%s%%), so %s kg goes into the tank.',
            number_format($grossKg), number_format($loss), $percent, number_format($grossKg - $loss));
    }
}
