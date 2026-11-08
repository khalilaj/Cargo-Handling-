<?php

namespace App\Domain\Access;

use App\Models\Berth;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Tank;
use App\Models\Transporter;
use App\Models\Truck;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Model;

/** What still uses a register record. A record that is used is archived, never deleted: history refers to it. */
class RegisterUsage
{
    /** @return list<string> one sentence per thing that still uses the record, empty when nothing does */
    public static function blockers(Model $record): array
    {
        $label = method_exists($record, 'auditLabel') ? $record->auditLabel() : class_basename($record);

        $uses = match (true) {
            $record instanceof Tank => [[$record->movements()->count(), 'ledger movement']],
            $record instanceof Vessel => [[$record->voyages()->count(), 'voyage']],
            $record instanceof Berth => [[$record->voyages()->count(), 'voyage']],
            $record instanceof Customer => [[$record->orders()->count(), 'delivery order']],
            $record instanceof Transporter => [[$record->trucks()->count(), 'truck'], [$record->drivers()->count(), 'driver']],
            $record instanceof Truck, $record instanceof Driver => [[$record->visits()->count(), 'truck visit']],
            default => [],
        };

        return collect($uses)
            ->filter(fn (array $u) => $u[0] > 0)
            ->map(fn (array $u) => "{$label} has {$u[0]} ".$u[1].($u[0] === 1 ? '' : 's').'.')
            ->values()->all();
    }
}
