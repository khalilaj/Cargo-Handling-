<?php

namespace Tests\Feature;

use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Models\Berth;
use App\Models\CargoMovement;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Tank;
use App\Models\Transporter;
use App\Models\Truck;
use App\Models\Vessel;
use App\Models\VesselDischarge;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ExtraDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The fuller demo: more of every register, ship and order history through the real ledger, and safe to re-run. */
class ExtraDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function counts(): array
    {
        return [
            Tank::count(), Berth::count(), Vessel::count(), Customer::count(), Transporter::count(), Truck::count(),
            Driver::count(), VesselDischarge::count(), DeliveryOrder::count(), CargoMovement::count(),
        ];
    }

    public function test_it_adds_to_every_register_and_to_the_operations(): void
    {
        $this->seed(DatabaseSeeder::class);                                  // extras are off under test: the base demo only
        $this->assertSame([5, 2, 5, 4, 2, 4, 3, 5, 4, 6], $this->counts());

        $this->seed(ExtraDemoSeeder::class);

        // tanks, berths, vessels, customers, transporters, trucks, drivers, voyages, orders, ledger rows
        $this->assertSame([8, 4, 9, 8, 4, 10, 7, 10, 10, 15], $this->counts());     // ledger: 6 base rows + 5 pumped in + 4 loaded out
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(ExtraDemoSeeder::class);
        $once = $this->counts();

        $this->seed(ExtraDemoSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($once, $this->counts());
    }

    public function test_it_does_not_crash_when_a_voyage_reference_is_already_taken(): void
    {
        $this->seed(DatabaseSeeder::class);
        // A live database that already holds one of the demo refs, but not the first one.
        VesselDischarge::where('voyage_ref', 'VOY-2611-05')->update(['voyage_ref' => 'VOY-2611-06']);

        $this->seed(ExtraDemoSeeder::class);

        $this->assertSame(1, VesselDischarge::where('voyage_ref', 'VOY-2611-06')->count());
    }

    public function test_the_seeder_hooks_in_when_the_flag_is_on(): void
    {
        config(['demo.extra' => true]);
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(VesselDischarge::where('voyage_ref', 'VOY-2610-01')->exists());
        $this->assertSame(8, Tank::count());
    }

    public function test_tank_levels_match_the_ledger_after_the_extra_history(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(ExtraDemoSeeder::class);

        $level = fn (string $code) => Tank::where('code', $code)->firstOrFail()->current_volume_kg;

        $this->assertSame(2_985_000 - 450_000 - 600_000, $level('CPO-03'));    // 3,000,000 in less 0.5%, 1,050,000 out
        $this->assertSame(1_990_000 - 300_000, $level('CPOL-03'));
        $this->assertSame(1_492_500 - 250_000, $level('PFAD-02'));
    }

    public function test_finished_voyages_are_fully_received_with_the_half_percent_loss(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(ExtraDemoSeeder::class);

        foreach (['VOY-2610-01' => 15_000, 'VOY-2610-02' => 10_000, 'VOY-2611-06' => 7_500] as $ref => $loss) {
            $v = VesselDischarge::where('voyage_ref', $ref)->firstOrFail();

            $this->assertSame(DischargeStatus::Completed, $v->status);
            $this->assertTrue($v->isFullyDischarged(), $ref);
            $this->assertSame($v->bl_quantity_kg, $v->receivedGrossKg(), $ref);
            $this->assertSame($loss, $v->transitLossKg(), $ref);
        }
    }

    public function test_delivered_orders_are_fully_delivered_and_open_ones_are_waiting(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(ExtraDemoSeeder::class);

        foreach (['ORD-1005', 'ORD-1006', 'ORD-1007', 'ORD-1008'] as $ref) {
            $o = DeliveryOrder::withTotals()->where('order_ref', $ref)->firstOrFail();
            $this->assertSame(OrderStatus::Completed, $o->status);
            $this->assertSame(0, $o->remainingKg(), $ref);
        }

        foreach (['ORD-1009', 'ORD-1010'] as $ref) {
            $this->assertSame(OrderStatus::Pending, DeliveryOrder::where('order_ref', $ref)->firstOrFail()->status);
        }
        $this->assertSame(3, VesselDischarge::where('status', DischargeStatus::Scheduled->value)->count());   // the two new ships, plus VOY-2611-05
    }

    public function test_the_new_berths_are_free_so_a_scheduled_ship_can_be_berthed(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(ExtraDemoSeeder::class);

        $free = Berth::where('active', true)->get()->filter(fn (Berth $b) => $b->occupant() === null)->pluck('code')->sort()->values()->all();

        $this->assertSame(['B3', 'B4'], $free);
    }
}
