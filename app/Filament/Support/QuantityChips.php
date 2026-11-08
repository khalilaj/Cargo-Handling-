<?php

namespace App\Filament\Support;

use App\Domain\Cargo\Services\TransitLossCalculator;

/**
 * Quick amounts for the quantity field, worked out from the real limits so nobody has to type a long number
 * or guess what fits. Nothing here bypasses a rule: the domain still checks every quantity that is booked.
 */
final class QuantityChips
{
    /** Round DOWN to a step that suits the size, so a chip never goes over a limit. */
    public static function nice(int $kg): int
    {
        $step = match (true) {
            $kg < 20_000 => 1_000,
            $kg < 100_000 => 5_000,
            $kg < 500_000 => 10_000,
            $kg < 2_000_000 => 50_000,
            default => 100_000,
        };

        return intdiv($kg, $step) * $step;
    }

    /** The largest gross quantity that still fits in $freeKg once the transit loss has come off. */
    public static function grossThatFits(int $freeKg): int
    {
        if ($freeKg <= 0) {
            return 0;
        }

        $loss = new TransitLossCalculator;
        $gross = intdiv($freeKg * 1000, 1000 - TransitLossCalculator::LOSS_PER_MILLE);

        while ($gross > 0 && $gross - $loss->lossFor($gross) > $freeKg) {
            $gross--;
        }
        while (($gross + 1) - $loss->lossFor($gross + 1) <= $freeKg) {   // rounding can leave room for one more
            $gross++;
        }

        return $gross;
    }

    /**
     * One chip per useful amount: a quarter and a half of what fits, and everything that fits.
     * The label of the last chip names the limit that decides it (the smallest one).
     *
     * @param  array<string, int>  $limits  limit name => kilograms, for example ['All remaining' => 2_200_000]
     * @return list<array{label: string, kg: int}>
     */
    public static function from(array $limits): array
    {
        $limits = array_filter($limits, fn (int $kg) => $kg > 0);
        if ($limits === []) {
            return [];
        }

        asort($limits);                                  // smallest first: that one decides
        $name = array_key_first($limits);
        $cap = $limits[$name];

        $chips = [];
        foreach ([['Quarter', 4], ['Half', 2]] as [$label, $parts]) {
            $kg = self::nice(intdiv($cap, $parts));
            if ($kg > 0 && $kg < $cap && ! in_array($kg, array_column($chips, 'kg'), true)) {
                $chips[] = ['label' => $label, 'kg' => $kg];
            }
        }
        $chips[] = ['label' => $name, 'kg' => $cap];

        return $chips;
    }
}
