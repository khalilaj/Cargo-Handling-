<?php

namespace Tests\Feature;

use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Domain\Reporting\StockPositionReport;
use App\Models\Berth;
use App\Models\CargoMovement;
use App\Models\CorrectionRequest;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Tank;
use App\Models\Transporter;
use App\Models\Truck;
use App\Models\TruckVisit;
use App\Models\Vessel;
use App\Models\VesselDischarge;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The live demo yard: three tanks, ships in port, trucks on site, all through the real ledger actions. */
class DemoYardSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedYard(): void
    {
        config(['demo.extra' => true]);
        $this->seed(DatabaseSeeder::class);
    }

    private function counts(): array
    {
        return [
            Tank::count(), Berth::count(), Vessel::count(), Customer::count(), Transporter::count(), Truck::count(),
            Driver::count(), VesselDischarge::count(), DeliveryOrder::count(), CargoMovement::count(),
            TruckVisit::count(), CorrectionRequest::count(),
        ];
    }

    public function test_the_yard_has_exactly_three_tanks_one_per_product(): void
    {
        $this->seedYard();

        $this->assertSame(['CPO-01', 'CPOL-01', 'PFAD-01'], Tank::orderBy('code')->pluck('code')->all());
        $this->assertEqualsCanonicalizing(
            ['cpo', 'cpol', 'pfad'],
            Tank::get()->map(fn (Tank $t) => $t->commodity->value)->all(),
        );
        $this->assertTrue(Tank::where('code', 'CPO-01')->firstOrFail()->requires_heating);
    }

    public function test_two_ships_are_alongside_and_the_third_berth_is_free(): void
    {
        $this->seedYard();

        $alongside = VesselDischarge::whereIn('status', [DischargeStatus::Berthed->value, DischargeStatus::Discharging->value])->get();
        $this->assertCount(2, $alongside);
        $this->assertEqualsCanonicalizing(
            [DischargeStatus::Berthed, DischargeStatus::Discharging],
            $alongside->pluck('status')->all(),
        );

        $this->assertSame(
            $alongside->pluck('id')->sort()->values()->all(),
            collect(['B1', 'B2'])->map(fn ($code) => Berth::where('code', $code)->firstOrFail()->occupant()->id)->sort()->values()->all(),
        );
        $this->assertNull(Berth::where('code', 'B3')->firstOrFail()->occupant());
        $this->assertSame(3, Berth::count());

        $discharging = $alongside->firstWhere('status', DischargeStatus::Discharging);
        $this->assertGreaterThan(0, $discharging->receivedGrossKg());
        $this->assertLessThan($discharging->bl_quantity_kg, $discharging->receivedGrossKg());
    }

    public function test_two_scheduled_ships_have_future_etas(): void
    {
        $this->seedYard();

        $scheduled = VesselDischarge::where('status', DischargeStatus::Scheduled->value)->get();

        $this->assertCount(2, $scheduled);
        foreach ($scheduled as $v) {
            $this->assertTrue($v->eta->isFuture(), $v->voyage_ref);
        }
    }

    public function test_five_trucks_are_on_site_across_the_four_possible_stages(): void
    {
        $this->seedYard();

        $open = TruckVisit::whereNotNull('active_truck_id')->get();

        $this->assertGreaterThanOrEqual(5, $open->count());
        // The open stages are arrived, weighed in, loading and weighed out: four is the most there can be.
        $this->assertEqualsCanonicalizing(
            [VisitStatus::Arrived, VisitStatus::WeighedIn, VisitStatus::Loading, VisitStatus::WeighedOut],
            $open->pluck('status')->unique()->all(),
        );
        foreach ($open as $visit) {
            $this->assertTrue($visit->gate_in_at->isPast());
            $this->assertTrue($visit->gate_in_at->gt(Carbon::now()->subDay()), 'gate-in is recent');
        }
    }

    public function test_orders_cover_history_loading_and_pending(): void
    {
        $this->seedYard();

        $this->assertSame(1, DeliveryOrder::where('status', OrderStatus::Loading->value)->count());
        $this->assertSame(2, DeliveryOrder::where('status', OrderStatus::Pending->value)->count());
        $this->assertGreaterThanOrEqual(4, DeliveryOrder::where('status', OrderStatus::Completed->value)->count());
        $this->assertGreaterThanOrEqual(3, VesselDischarge::where('status', DischargeStatus::Completed->value)->count());
        $this->assertSame(1, CorrectionRequest::count());
    }

    public function test_the_ledger_reconciles_and_every_tank_is_within_its_limits(): void
    {
        $this->seedYard();

        $report = (new StockPositionReport)(Carbon::parse('2026-01-01'), Carbon::now()->addYear());
        foreach ($report['rows'] as $row) {
            $this->assertTrue($row['reconciles'], $row['commodity']->value);
        }

        foreach (Tank::get() as $tank) {
            $this->assertGreaterThanOrEqual(0, $tank->current_volume_kg, $tank->code);
            $this->assertLessThanOrEqual($tank->capacity_kg, $tank->current_volume_kg, $tank->code);
            $this->assertSame($tank->current_volume_kg, $tank->opening_volume_kg + $tank->movements->sum(fn ($m) => $m->delta()), $tank->code);
        }
        $this->assertCount(3, $report['rows']);
    }

    public function test_transit_loss_is_half_a_percent_of_what_was_pumped(): void
    {
        $this->seedYard();

        foreach (CargoMovement::whereNotNull('vessel_discharge_id')->get() as $m) {
            $this->assertSame((int) round($m->gross_kg * 0.005), $m->loss_kg);
        }
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->seedYard();
        $once = $this->counts();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($once, $this->counts());
    }

    public function test_it_survives_a_database_that_already_holds_a_demo_voyage_ref(): void
    {
        config(['demo.extra' => true]);
        $vessel = Vessel::create(['name' => 'MV Old Timer']);
        VesselDischarge::create(['voyage_ref' => 'VOY-2611-11', 'vessel_id' => $vessel->id, 'vessel_name' => $vessel->name,
            'commodity' => Commodity::CPO, 'bl_quantity_kg' => 1_000, 'status' => DischargeStatus::Scheduled, 'eta' => now()->addDay()]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, VesselDischarge::count());
        $this->assertSame(3, Tank::count());
    }

    public function test_it_survives_older_stock_in_the_demo_tanks(): void
    {
        config(['demo.extra' => false]);
        $this->seed(DatabaseSeeder::class);                 // the base demo: CPO-01 already holds 3.8 million kg
        config(['demo.extra' => true]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, VesselDischarge::where('voyage_ref', 'VOY-2611-15')->count());
    }

    public function test_the_base_demo_is_untouched_when_the_flag_is_off(): void
    {
        config(['demo.extra' => false]);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(5, Tank::count());
        $this->assertSame(2, Berth::count());
        $this->assertSame(3, TruckVisit::count());
    }
}
