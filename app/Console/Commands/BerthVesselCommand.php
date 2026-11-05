<?php

namespace App\Console\Commands;

use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Operations\Actions\AdvanceDischargeStatus;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Models\Berth;
use App\Models\VesselDischarge;
use Illuminate\Console\Command;

class BerthVesselCommand extends Command
{
    protected $signature = 'cargo:berth {voyage : voyage ref} {berth : berth code}';

    protected $description = 'Mark a vessel berthed at a berth (same rules as the dashboard).';

    public function handle(AdvanceDischargeStatus $advance): int
    {
        try {
            $advance(
                VesselDischarge::where('voyage_ref', $this->argument('voyage'))->firstOrFail(),
                DischargeStatus::Berthed,
                Berth::where('code', $this->argument('berth'))->firstOrFail(),
            );
            $this->line('berthed');

            return self::SUCCESS;
        } catch (CargoException $e) {
            $this->line('rejected '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
