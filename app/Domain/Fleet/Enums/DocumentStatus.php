<?php

namespace App\Domain\Fleet\Enums;

/** State of an expiring document (insurance, inspection, driving licence). */
enum DocumentStatus: string
{
    case Valid = 'valid';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';
    case Missing = 'missing';

    public const SOON_DAYS = 30;

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::ExpiringSoon => 'Expiring soon',
            self::Expired => 'Expired',
            self::Missing => 'Not recorded',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Valid => 'success',
            self::ExpiringSoon => 'warning',
            self::Expired => 'danger',
            self::Missing => 'gray',
        };
    }

    /** Worse = higher. Used to summarise several documents as one badge. */
    public function severity(): int
    {
        return match ($this) {
            self::Valid => 0,
            self::Missing => 1,
            self::ExpiringSoon => 2,
            self::Expired => 3,
        };
    }
}
