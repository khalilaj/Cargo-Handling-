<?php

namespace Tests\Feature;

use App\Domain\Cargo\Actions\RecordTruckDelivery;
use App\Domain\Cargo\Actions\RecordVesselDischarge;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Cargo\Exceptions\CommodityMismatchException;
use App\Domain\Cargo\Exceptions\InsufficientStockException;
use App\Domain\Cargo\Exceptions\TankCapacityExceededException;
use App\Models\CargoMovement;
use App\Models\Tank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TankLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function tank(int $capacity = 1_000_000, Commodity $c = Commodity::CPOL): Tank
    {
        return Tank::create([
            'code' => 'T-'.uniqid(), 'name' => 'Test tank', 'commodity' => $c,
            'capacity_kg' => $capacity, 'requires_heating' => $c->requiresHeating(),
        ]);
    }

    private function discharge(Tank $t, int $kg, string $at, string $key)
    {
        return app(RecordVesselDischarge::class)($t->id, $kg, Carbon::parse($at), $key, 'MV Test');
    }

    private function deliver(Tank $t, int $kg, string $at, string $key)
    {
        return app(RecordTruckDelivery::class)($t->id, $kg, Carbon::parse($at), $key, 'KDA 123X');
    }

    public function test_inbound_discharge_loses_half_a_percent(): void
    {
        $t = $this->tank();
        $m = $this->discharge($t, 100_000, '2026-11-05 08:00', 'a');

        $this->assertSame(500, $m->loss_kg);
        $this->assertSame(99_500, $m->net_kg);
        $this->assertSame(99_500, $t->fresh()->current_volume_kg);
    }

    public function test_outbound_delivery_has_no_loss(): void
    {
        $t = $this->tank();
        $this->discharge($t, 100_000, '2026-11-05 08:00', 'a');
        $m = $this->deliver($t, 20_000, '2026-11-05 09:00', 'b');

        $this->assertSame(0, $m->loss_kg);
        $this->assertSame(79_500, $t->fresh()->current_volume_kg);
    }

    public function test_overflow_throws_domain_exception_and_changes_nothing(): void
    {
        $t = $this->tank(100_000);
        $this->discharge($t, 90_000, '2026-11-05 08:00', 'a'); // net 89,550

        try {
            $this->discharge($t, 20_000, '2026-11-05 09:00', 'b');
            $this->fail('Expected overflow');
        } catch (TankCapacityExceededException $e) {
            $this->assertStringContainsString('capacity', $e->getMessage());
        }

        $this->assertSame(89_550, $t->fresh()->current_volume_kg);
        $this->assertSame(1, CargoMovement::count());
    }

    public function test_cannot_deliver_more_than_is_in_the_tank(): void
    {
        $t = $this->tank();
        $this->discharge($t, 10_000, '2026-11-05 08:00', 'a');

        $this->expectException(InsufficientStockException::class);
        $this->deliver($t, 50_000, '2026-11-05 09:00', 'b');
    }

    public function test_out_of_order_records_give_same_balance_as_in_order(): void
    {
        $inOrder = $this->tank();
        $this->discharge($inOrder, 100_000, '2026-11-05 08:00', 'a1');
        $this->deliver($inOrder, 40_000, '2026-11-05 10:00', 'a2');
        $this->discharge($inOrder, 50_000, '2026-11-05 12:00', 'a3');

        $shuffled = $this->tank();
        $this->discharge($shuffled, 50_000, '2026-11-05 12:00', 'b3');   // arrives first
        $this->discharge($shuffled, 100_000, '2026-11-05 08:00', 'b1');  // late
        $this->deliver($shuffled, 40_000, '2026-11-05 10:00', 'b2');     // late

        $this->assertSame($inOrder->fresh()->current_volume_kg, $shuffled->fresh()->current_volume_kg);
        $this->assertSame(109_250, $shuffled->fresh()->current_volume_kg);
    }

    public function test_late_delivery_that_would_have_gone_negative_in_real_time_is_rejected(): void
    {
        $t = $this->tank();
        $this->discharge($t, 100_000, '2026-11-05 12:00', 'a');

        // Truck left at 08:00, before any oil had arrived -> impossible timeline.
        $this->expectException(InsufficientStockException::class);
        $this->deliver($t, 10_000, '2026-11-05 08:00', 'b');
    }

    public function test_duplicate_idempotency_key_is_applied_once(): void
    {
        $t = $this->tank();
        $first = $this->discharge($t, 100_000, '2026-11-05 08:00', 'same');
        $again = $this->discharge($t, 100_000, '2026-11-05 08:00', 'same');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, CargoMovement::count());
        $this->assertSame(99_500, $t->fresh()->current_volume_kg);
    }

    public function test_wrong_commodity_is_refused(): void
    {
        $t = $this->tank(1_000_000, Commodity::CPO);

        $this->expectException(CommodityMismatchException::class);
        app(RecordVesselDischarge::class)($t->id, 1_000, Carbon::now(), 'x', 'MV Test', Commodity::PFAD);
    }
}
