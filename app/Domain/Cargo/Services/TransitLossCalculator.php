<?php

namespace App\Domain\Cargo\Services;

/**
 * Bulk liquid operations lose 0.5% of mass in transit. Applied to inbound
 * vessel flows only. Integer maths (kg), half-up rounding, one place only.
 */
class TransitLossCalculator
{
    public const LOSS_PER_MILLE = 5; // 0.5% = 5/1000

    public function lossFor(int $grossKg): int
    {
        return intdiv($grossKg * self::LOSS_PER_MILLE + 500, 1000);
    }

    public function netFor(int $grossKg): int
    {
        return $grossKg - $this->lossFor($grossKg);
    }
}
