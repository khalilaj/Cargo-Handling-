<?php

namespace App\Domain\Cargo\Exceptions;

class TankCapacityExceededException extends CargoException
{
    public static function for(string $tankCode, int $capacityKg, int $attemptedKg): self
    {
        return new self(sprintf(
            'Tank %s capacity of %s kg would be exceeded (attempted level: %s kg).',
            $tankCode, number_format($capacityKg), number_format($attemptedKg)
        ));
    }
}
