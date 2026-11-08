<?php

namespace App\Domain\Cargo\Exceptions;

class TankArchivedException extends CargoException
{
    public static function for(string $tankCode): self
    {
        return new self(sprintf('Tank %s is archived and cannot take new movements. An admin can restore it.', $tankCode));
    }
}
