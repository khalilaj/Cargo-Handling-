<?php

namespace App\Domain\Cargo\Actions;

use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Cargo\Enums\MovementType;
use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Cargo\Services\TankLedger;
use App\Models\Tank;
use Illuminate\Support\Carbon;

/**
 * Message-pipeline entry point. Records may arrive in any order: the batch is
 * sorted by timestamp first, and every record is also replayed against the
 * full ledger, so earlier-arriving late records are handled too.
 */
class IngestMovements
{
    public function __construct(private TankLedger $ledger)
    {
    }

    /** @param array<int, array<string, mixed>> $records */
    public function __invoke(array $records): array
    {
        usort($records, fn ($a, $b) => Carbon::parse($a['occurred_at'])->getTimestamp()
            <=> Carbon::parse($b['occurred_at'])->getTimestamp());

        $results = [];
        foreach ($records as $r) {
            try {
                $tank = Tank::where('code', $r['tank'])->firstOrFail();
                $m = $this->ledger->record(
                    $tank->id,
                    MovementType::from($r['type']),
                    (int) $r['quantity_kg'],
                    Carbon::parse($r['occurred_at']),
                    $r['idempotency_key'],
                    $r['reference'] ?? null,
                    isset($r['commodity']) ? Commodity::from($r['commodity']) : null,
                );
                $results[] = ['idempotency_key' => $r['idempotency_key'], 'status' => 'applied', 'net_kg' => $m->net_kg];
            } catch (CargoException $e) {
                $results[] = ['idempotency_key' => $r['idempotency_key'], 'status' => 'rejected', 'reason' => $e->getMessage()];
            }
        }

        return $results;
    }
}
