<?php

namespace App\Domain\Cargo\Services;

/**
 * Bulk liquid operations lose 0.5% of mass in transit. Applied to inbound
 * vessel flows only. Integer maths (kg), half-up rounding, one place only.
 */
class TransitLossCalculator
{
    public const LOSS_PER_MILLE = 5; // 0.5% = 5/1000

    /** The rate as screens show it, e.g. "0.5%", so no label hardcodes the number. */
    public static function percentLabel(): string
    {
        return rtrim(rtrim(number_format(self::LOSS_PER_MILLE / 10, 2), '0'), '.').'%';
    }

    public function lossFor(int $grossKg): int
    {
        return intdiv($grossKg * self::LOSS_PER_MILLE + 500, 1000);
    }

    public function netFor(int $grossKg): int
    {
        return $grossKg - $this->lossFor($grossKg);
    }
}
