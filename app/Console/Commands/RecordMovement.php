<?php

namespace App\Console\Commands;

use App\Domain\Cargo\Enums\MovementType;
use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Cargo\Services\TankLedger;
use App\Domain\Operations\Actions\RecordDischargeIntoTank;
use App\Domain\Operations\Actions\RecordTruckLoad;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\TruckVisit;
use App\Models\VesselDischarge;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RecordMovement extends Command
{
    protected $signature = 'cargo:record {tank : tank code} {type : vessel_discharge|truck_delivery}
                            {kg : gross kg} {key : idempotency key} {--at= : timestamp, default now}
                            {--discharge= : voyage ref: record against a vessel discharge} {--order= : order ref: record against a delivery order}
                            {--visit= : truck visit id, with --order: load onto that truck}';

    protected $description = 'Record one cargo movement (same rules as the dashboard).';

    public function handle(TankLedger $ledger): int
    {
        try {
            $tankId = Tank::where('code', $this->argument('tank'))->firstOrFail()->id;
            $kg = (int) $this->argument('kg');
            $at = Carbon::parse($this->option('at') ?: now());
            $key = $this->argument('key');

            $m = match (true) {
                (bool) $this->option('discharge') => app(RecordDischargeIntoTank::class)(
                    VesselDischarge::where('voyage_ref', $this->option('discharge'))->firstOrFail(), $tankId, $kg, $at, $key),
                (bool) $this->option('order') => app(RecordTruckLoad::class)(
                    DeliveryOrder::where('order_ref', $this->option('order'))->firstOrFail(), $tankId, $kg, $at, $key, null,
                    $this->option('visit') ? TruckVisit::findOrFail($this->option('visit')) : null),
                default => $ledger->record($tankId, MovementType::from($this->argument('type')), $kg, $at, $key),
            };
            $this->line("applied net_kg={$m->net_kg}");

            return self::SUCCESS;
        } catch (CargoException $e) {
            $this->line('rejected '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
