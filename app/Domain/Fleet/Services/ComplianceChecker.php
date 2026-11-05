<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Enums\DocumentStatus;
use App\Models\Driver;
use App\Models\Truck;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Turns expiry dates into statuses and plain-language warnings.
 * Policy decision: documents WARN, they never block a truck or driver.
 */
class ComplianceChecker
{
    public static function statusOf(?CarbonInterface $expiry, ?CarbonInterface $on = null): DocumentStatus
    {
        $today = ($on ?? Carbon::now())->copy()->startOfDay();

        return match (true) {
            $expiry === null => DocumentStatus::Missing,
            $expiry->copy()->startOfDay()->lt($today) => DocumentStatus::Expired,
            $expiry->copy()->startOfDay()->lte($today->copy()->addDays(DocumentStatus::SOON_DAYS)) => DocumentStatus::ExpiringSoon,
            default => DocumentStatus::Valid,
        };
    }

    /** @return list<array{item: string, status: DocumentStatus, expiry: ?CarbonInterface}> */
    public function documentsFor(Truck $truck, ?Driver $driver = null, ?CarbonInterface $on = null): array
    {
        $docs = [
            ['item' => "Truck {$truck->plate} insurance", 'expiry' => $truck->insurance_expiry],
            ['item' => "Truck {$truck->plate} inspection", 'expiry' => $truck->inspection_expiry],
        ];
        if ($driver) {
            $docs[] = ['item' => "Driver {$driver->name} licence", 'expiry' => $driver->licence_expiry];
        }

        return array_map(fn ($d) => $d + ['status' => self::statusOf($d['expiry'], $on)], $docs);
    }

    /** @return list<string> one sentence per document that needs attention */
    public function warningsFor(Truck $truck, ?Driver $driver = null, ?CarbonInterface $on = null): array
    {
        $today = ($on ?? Carbon::now())->copy()->startOfDay();
        $out = [];

        foreach ($this->documentsFor($truck, $driver, $on) as $d) {
            $days = $d['expiry'] ? (int) $today->diffInDays($d['expiry']->copy()->startOfDay(), false) : null;

            $out[] = match ($d['status']) {
                DocumentStatus::Expired => "{$d['item']} expired ".abs($days).' day'.(abs($days) === 1 ? '' : 's')." ago ({$d['expiry']->toDateString()})",
                DocumentStatus::ExpiringSoon => "{$d['item']} expires ".($days === 0 ? 'today' : "in {$days} day".($days === 1 ? '' : 's'))." ({$d['expiry']->toDateString()})",
                DocumentStatus::Missing => "{$d['item']} expiry date is not recorded",
                DocumentStatus::Valid => null,
            } ?? '';
        }

        return array_values(array_filter($out));
    }

    /** Worst status among a set of expiry dates (for one summary badge). */
    public static function worst(array $expiries, ?CarbonInterface $on = null): DocumentStatus
    {
        $worst = DocumentStatus::Valid;
        foreach ($expiries as $e) {
            $s = self::statusOf($e, $on);
            if ($s->severity() > $worst->severity()) {
                $worst = $s;
            }
        }

        return $worst;
    }
}
