<?php

namespace App\Console\Commands;

use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Fleet\Actions\TruckVisitWorkflow;
use App\Models\DeliveryOrder;
use App\Models\Truck;
use Illuminate\Console\Command;

class GateInTruckCommand extends Command
{
    protected $signature = 'cargo:gate-in {order : order ref} {plate : number plate}';

    protected $description = 'Gate a truck in for an order (same rules as the dashboard).';

    public function handle(TruckVisitWorkflow $flow): int
    {
        try {
            $visit = $flow->gateIn(
                DeliveryOrder::where('order_ref', $this->argument('order'))->firstOrFail(),
                Truck::where('plate', strtoupper($this->argument('plate')))->firstOrFail(),
            );
            $this->line("gated-in {$visit->ref}");

            return self::SUCCESS;
        } catch (CargoException $e) {
            $this->line('rejected '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
