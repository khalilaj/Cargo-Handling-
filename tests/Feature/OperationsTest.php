<?php

namespace Tests\Feature;

use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Cargo\Exceptions\CommodityMismatchException;
use App\Domain\Cargo\Exceptions\InsufficientStockException;
use App\Domain\Operations\Actions\AdvanceDischargeStatus;
use App\Domain\Operations\Actions\AdvanceOrderStatus;
use App\Domain\Operations\Actions\RecordDischargeIntoTank;
use App\Domain\Operations\Actions\RecordTruckLoad;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Domain\Operations\Exceptions\BillOfLadingExceededException;
use App\Domain\Operations\Exceptions\InvalidStatusTransitionException;
use App\Domain\Operations\Exceptions\OperationIncompleteException;
use App\Domain\Operations\Exceptions\OperationNotActiveException;
use App\Domain\Operations\Exceptions\OrderQuantityExceededException;
use App\Models\CargoMovement;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\VesselDischarge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private function tank(Commodity $c = Commodity::CPOL, int $cap = 10_000_000): Tank
    {
        return Tank::create(['code' => 'T-' . uniqid(), 'name' => 'T', 'commodity' => $c, 'capacity_kg' => $cap]);
    }

    private function discharge(int $bl = 1_000_000, Commodity $c = Commodity::CPOL, ?DischargeStatus $s = null): VesselDischarge
    {
        return VesselDischarge::create([
            'voyage_ref' => 'V-' . uniqid(), 'vessel_name' => 'MV Test', 'commodity' => $c,
            'bl_quantity_kg' => $bl, 'status' => $s ?? DischargeStatus::Discharging,
        ]);
    }

    private function order(int $qty = 500_000, ?OrderStatus $s = null, Commodity $c = Commodity::CPOL): DeliveryOrder
    {
        return DeliveryOrder::create([
            'order_ref' => 'O-' . uniqid(), 'customer' => 'Acme Foods', 'commodity' => $c,
            'ordered_kg' => $qty, 'status' => $s ?? OrderStatus::Loading,
        ]);
    }

    private function pump(VesselDischarge $d, Tank $t, int $kg, string $key)
    {
        return app(RecordDischargeIntoTank::class)($d, $t->id, $kg, Carbon::parse('2026-11-05 08:00'), $key);
    }

    private function load(DeliveryOrder $o, Tank $t, int $kg, string $key)
    {
        return app(RecordTruckLoad::class)($o, $t->id, $kg, Carbon::parse('2026-11-05 12:00'), $key, 'KDA 1X');
    }

    // ---- lifecycle ---------------------------------------------------------

    public function test_discharge_follows_the_lifecycle_in_order(): void
    {
        $d = $this->discharge(s: DischargeStatus::Scheduled);
        $advance = app(AdvanceDischargeStatus::class);

        $d = $advance($d, DischargeStatus::Berthed);
        $this->assertNotNull($d->berthed_at);
        $d = $advance($d, DischargeStatus::Discharging);
        $this->assertNotNull($d->started_at);

        $this->pump($d, $this->tank(), 100_000, 'k1');
        $d = $advance($d, DischargeStatus::Completed);

        $this->assertSame(DischargeStatus::Completed, $d->status);
        $this->assertNotNull($d->completed_at);
    }

    public function test_discharge_cannot_skip_a_step(): void
    {
        $d = $this->discharge(s: DischargeStatus::Scheduled);

        $this->expectException(InvalidStatusTransitionException::class);
        app(AdvanceDischargeStatus::class)($d, DischargeStatus::Discharging);
    }

    public function test_finished_operations_cannot_be_reopened(): void
    {
        $d = $this->discharge(s: DischargeStatus::Cancelled);

        $this->expectException(InvalidStatusTransitionException::class);
        app(AdvanceDischargeStatus::class)($d, DischargeStatus::Scheduled);
    }

    public function test_cannot_complete_a_discharge_that_received_nothing(): void
    {
        $this->expectException(OperationIncompleteException::class);
        app(AdvanceDischargeStatus::class)($this->discharge(), DischargeStatus::Completed);
    }

    public function test_cannot_cancel_a_discharge_that_already_put_cargo_in_tanks(): void
    {
        $d = $this->discharge();
        $this->pump($d, $this->tank(), 50_000, 'k1');

        $this->expectException(OperationIncompleteException::class);
        app(AdvanceDischargeStatus::class)($d, DischargeStatus::Cancelled);
    }

    public function test_order_lifecycle_and_guards(): void
    {
        $o = $this->order(s: OrderStatus::Pending);
        $advance = app(AdvanceOrderStatus::class);

        $o = $advance($o, OrderStatus::Loading);
        $this->assertNotNull($o->started_at);

        $this->expectException(OperationIncompleteException::class);   // nothing loaded yet
        $advance($o, OrderStatus::Completed);
    }

    // ---- discharge into tanks ---------------------------------------------

    public function test_pumping_links_the_movement_and_tracks_received_totals(): void
    {
        $d = $this->discharge(1_000_000);
        $t = $this->tank();

        $m = $this->pump($d, $t, 400_000, 'k1');
        $this->pump($d, $t, 200_000, 'k2');

        $this->assertSame($d->id, $m->vessel_discharge_id);
        $d = VesselDischarge::withTotals()->find($d->id);
        $this->assertSame(600_000, $d->receivedGrossKg());
        $this->assertSame(3_000, $d->transitLossKg());          // 0.5% of 600,000
        $this->assertSame(597_000, $d->receivedNetKg());
        $this->assertSame(400_000, $d->remainingKg());
        $this->assertSame(400_000, $d->varianceKg());
        $this->assertSame(60.0, $d->progressPercent());
        $this->assertSame(597_000, $t->fresh()->current_volume_kg);
    }

    public function test_cannot_pump_unless_the_discharge_is_active(): void
    {
        $d = $this->discharge(s: DischargeStatus::Berthed);

        $this->expectException(OperationNotActiveException::class);
        $this->pump($d, $this->tank(), 1_000, 'k1');
    }

    public function test_cannot_discharge_more_than_the_bill_of_lading(): void
    {
        $d = $this->discharge(100_000);
        $t = $this->tank();
        $this->pump($d, $t, 80_000, 'k1');

        try {
            $this->pump($d, $t, 30_000, 'k2');
            $this->fail('Expected BL exceeded');
        } catch (BillOfLadingExceededException $e) {
            $this->assertStringContainsString('20,000', $e->getMessage());
        }
        $this->assertSame(1, CargoMovement::count());
    }

    public function test_wrong_product_into_a_tank_is_refused(): void
    {
        $d = $this->discharge(c: Commodity::CPOL);

        $this->expectException(CommodityMismatchException::class);
        $this->pump($d, $this->tank(Commodity::PFAD), 1_000, 'k1');
    }

    public function test_retrying_a_pump_with_the_same_key_is_not_double_counted(): void
    {
        $d = $this->discharge(100_000);
        $t = $this->tank();

        $first = $this->pump($d, $t, 100_000, 'same');   // uses the whole bill of lading
        $again = $this->pump($d, $t, 100_000, 'same');   // retry must NOT hit the BL cap

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, CargoMovement::count());
    }

    // ---- truck loading ------------------------------------------------------

    public function test_loading_a_truck_links_the_movement_and_counts_delivered(): void
    {
        $t = $this->tank();
        $this->pump($this->discharge(), $t, 400_000, 'in');
        $o = $this->order(300_000);

        $m = $this->load($o, $t, 120_000, 'out1');
        $this->load($o, $t, 30_000, 'out2');

        $this->assertSame($o->id, $m->delivery_order_id);
        $o = DeliveryOrder::withTotals()->find($o->id);
        $this->assertSame(150_000, $o->deliveredKg());
        $this->assertSame(150_000, $o->remainingKg());
        $this->assertSame(50.0, $o->progressPercent());
    }

    public function test_cannot_load_more_than_the_order(): void
    {
        $t = $this->tank();
        $this->pump($this->discharge(), $t, 900_000, 'in');
        $o = $this->order(100_000);

        $this->expectException(OrderQuantityExceededException::class);
        $this->load($o, $t, 150_000, 'out1');
    }

    public function test_cannot_load_unless_the_order_is_loading(): void
    {
        $o = $this->order(s: OrderStatus::Pending);

        $this->expectException(OperationNotActiveException::class);
        $this->load($o, $this->tank(), 1_000, 'out1');
    }

    public function test_cannot_load_more_than_the_tank_holds(): void
    {
        $t = $this->tank();
        $this->pump($this->discharge(), $t, 10_000, 'in');   // tank holds 9,950
        $o = $this->order(500_000);

        $this->expectException(InsufficientStockException::class);
        $this->load($o, $t, 20_000, 'out1');
    }
}
