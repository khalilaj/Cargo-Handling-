<?php

namespace App\Domain\Cargo\Enums;

enum Commodity: string
{
    case CPO = 'cpo';
    case CPOL = 'cpol';
    case PFAD = 'pfad';

    public function label(): string
    {
        return match ($this) {
            self::CPO => 'Crude Palm Oil (CPO)',
            self::CPOL => 'Crude Palm Olein (CPOL)',
            self::PFAD => 'Palm Fatty Acid Distillate (PFAD)',
        };
    }

    /** CPO needs constant temperature control. */
    public function requiresHeating(): bool
    {
        return $this === self::CPO;
    }
}
