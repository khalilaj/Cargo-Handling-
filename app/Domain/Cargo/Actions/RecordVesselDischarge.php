<?php

namespace App\Domain\Cargo\Actions;

use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Cargo\Enums\MovementType;
use App\Domain\Cargo\Services\TankLedger;
use App\Models\CargoMovement;
use Carbon\CarbonInterface;

/** Inbound: vessel -> shore tank. The 0.5% transit loss is applied here. */
class RecordVesselDischarge
{
    public function __construct(private TankLedger $ledger)
    {
    }

    public function __invoke(
        int $tankId, int $grossKg, CarbonInterface $occurredAt,
        string $idempotencyKey, ?string $vessel = null, ?Commodity $commodity = null,
    ): CargoMovement {
        return $this->ledger->record(
            $tankId, MovementType::VesselDischarge, $grossKg,
            $occurredAt, $idempotencyKey, $vessel, $commodity
        );
    }
}
