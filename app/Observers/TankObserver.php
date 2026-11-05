<?php

namespace App\Observers;

use App\Models\Tank;
use Illuminate\Support\Facades\Log;

/** Backend entity state hook: flag a tank as it crosses 90% full. */
class TankObserver
{
    public const NEAR_FULL_PERCENT = 90.0;

    public function updated(Tank $tank): void
    {
        if (! $tank->wasChanged('current_volume_kg') || $tank->capacity_kg === 0) {
            return;
        }

        $before = $tank->getOriginal('current_volume_kg') / $tank->capacity_kg * 100;

        if ($before < self::NEAR_FULL_PERCENT && $tank->fillPercent() >= self::NEAR_FULL_PERCENT) {
            Log::warning("Tank {$tank->code} is now {$tank->fillPercent()}% full.");
        }
    }
}
