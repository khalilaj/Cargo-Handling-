<?php

namespace App\Domain\Cargo\Actions;

use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Cargo\Enums\MovementType;
use App\Domain\Cargo\Services\TankLedger;
use App\Models\CargoMovement;
use Carbon\CarbonInterface;

/** Outbound: storage tank -> truck. */
class RecordTruckDelivery
{
    public function __construct(private TankLedger $ledger)
    {
    }

    public function __invoke(
        int $tankId, int $grossKg, CarbonInterface $occurredAt,
        string $idempotencyKey, ?string $truck = null, ?Commodity $commodity = null,
    ): CargoMovement {
        return $this->ledger->record(
            $tankId, MovementType::TruckDelivery, $grossKg,
            $occurredAt, $idempotencyKey, $truck, $commodity
        );
    }
}
