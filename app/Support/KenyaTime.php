<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The terminal runs on Kenya time. Times are stored in UTC; use this wherever a time is formatted by hand,
 * so the same moment never reads differently on two screens.
 */
final class KenyaTime
{
    public const ZONE = 'Africa/Nairobi';

    public static function of(CarbonInterface $time): CarbonInterface
    {
        return $time->copy()->timezone(self::ZONE);
    }
}
