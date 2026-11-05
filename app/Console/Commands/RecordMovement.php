<?php

namespace App\Console\Commands;

use App\Domain\Cargo\Enums\MovementType;
use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Cargo\Services\TankLedger;
use App\Models\Tank;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RecordMovement extends Command
{
    protected $signature = 'cargo:record {tank : tank code} {type : vessel_discharge|truck_delivery}
                            {kg : gross kg} {key : idempotency key} {--at= : timestamp, default now}';
    protected $description = 'Record one cargo movement (same rules as the dashboard).';

    public function handle(TankLedger $ledger): int
    {
        try {
            $m = $ledger->record(
                Tank::where('code', $this->argument('tank'))->firstOrFail()->id,
                MovementType::from($this->argument('type')),
                (int) $this->argument('kg'),
                Carbon::parse($this->option('at') ?: now()),
                $this->argument('key'),
            );
            $this->line("applied net_kg={$m->net_kg}");
            return self::SUCCESS;
        } catch (CargoException $e) {
            $this->line('rejected ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
