<?php

namespace App\Domain\Cargo\Exceptions;

class InsufficientStockException extends CargoException
{
    public static function for(string $tankCode, int $attemptedKg): self
    {
        return new self(sprintf(
            'Tank %s would go below zero (attempted level: %s kg).',
            $tankCode, number_format($attemptedKg)
        ));
    }
}
