<?php

namespace App\Console\Commands;

use App\Domain\Cargo\Enums\Commodity;
use App\Models\Berth;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\Vessel;
use App\Models\VesselDischarge;
use Illuminate\Console\Command;

/** Creates one record WITHOUT a code, so the system numbers it. Used by the parallel-process tests. */
class CreateNumberedRecord extends Command
{
    protected $signature = 'cargo:new {kind : voyage|order|tank|berth}';

    protected $description = 'Create one voyage, order, tank or berth and print its generated code.';

    public function handle(): int
    {
        $record = match ($this->argument('kind')) {
            'voyage' => VesselDischarge::create(['vessel_id' => Vessel::firstOrFail()->id, 'commodity' => Commodity::CPO, 'bl_quantity_kg' => 1000]),
            'order' => DeliveryOrder::create(['customer_id' => Customer::firstOrFail()->id, 'commodity' => Commodity::CPOL, 'ordered_kg' => 1000]),
            'tank' => Tank::create(['name' => 'Parallel', 'commodity' => Commodity::CPO, 'capacity_kg' => 1000]),
            'berth' => Berth::create(['name' => 'Parallel']),
            default => null,
        };

        if (! $record) {
            $this->error('Unknown kind.');

            return self::INVALID;
        }

        $this->line((string) ($record->voyage_ref ?? $record->order_ref ?? $record->code));

        return self::SUCCESS;
    }
}
