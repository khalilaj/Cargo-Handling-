<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Cargo\Services\TransitLossCalculator;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Domain\Operations\Services\OperationalAlerts;
use App\Filament\Resources\TruckResource\Pages\CreateTruck;
use App\Filament\Widgets\StockOverview;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\User;
use App\Models\VesselDischarge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Regression tests for the issues found in the 2026-11-18 code review. */
class ReviewFindingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_overdue_alerts_show_the_kenya_time_not_raw_code(): void
    {
        Carbon::setTestNow('2026-11-18 12:00:00');
        VesselDischarge::create(['voyage_ref' => 'LATE-V', 'vessel_name' => 'MV Late', 'commodity' => Commodity::CPO,
            'bl_quantity_kg' => 100_000, 'status' => DischargeStatus::Scheduled, 'eta' => '2026-11-18 06:00:00']);
        DeliveryOrder::create(['order_ref' => 'LATE-O', 'customer' => 'Late Ltd', 'commodity' => Commodity::CPO,
            'ordered_kg' => 10_000, 'status' => OrderStatus::Pending, 'scheduled_at' => '2026-11-18 07:30:00']);

        $details = app(OperationalAlerts::class)->forRole(Role::Admin, 100)->pluck('detail')->implode(' | ');

        $this->assertStringNotContainsString('KenyaTime', $details);
        $this->assertStringContainsString('ETA was 18 Nov 09:00', $details);          // 06:00 UTC = 09:00 Nairobi
        $this->assertStringContainsString('scheduled for 18 Nov 10:30', $details);    // 07:30 UTC = 10:30 Nairobi
    }

    public function test_an_unknown_tank_in_a_batch_is_rejected_on_its_own(): void
    {
        config(['services.ingest.token' => 'secret']);
        Tank::create(['code' => 'CPOL-01', 'name' => 'T', 'commodity' => Commodity::CPOL, 'capacity_kg' => 1_000_000]);

        $res = $this->withToken('secret')->postJson('/api/movements/ingest', ['records' => [
            ['tank' => 'CPOL-01', 'type' => 'vessel_discharge', 'quantity_kg' => 100_000, 'occurred_at' => '2026-11-05 08:00:00', 'idempotency_key' => 'a1'],
            ['tank' => 'NOPE-99', 'type' => 'vessel_discharge', 'quantity_kg' => 100_000, 'occurred_at' => '2026-11-05 09:00:00', 'idempotency_key' => 'a2'],
        ]])->assertOk();

        $this->assertSame(['applied', 'rejected'], collect($res->json('results'))->pluck('status')->all());
        $this->assertStringContainsString('NOPE-99', $res->json('results.1.reason'));
    }

    public function test_the_ingest_endpoint_is_rate_limited(): void
    {
        config(['services.ingest.token' => 'secret']);

        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/movements/ingest', ['records' => []])->assertUnauthorized();
        }
        $this->postJson('/api/movements/ingest', ['records' => []])->assertStatus(429);
    }

    public function test_stock_overview_leaves_archived_tanks_out_of_stock_and_capacity(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Admin]));
        Tank::create(['code' => 'CPO-01', 'name' => 'A', 'commodity' => Commodity::CPO, 'capacity_kg' => 1_000_000, 'current_volume_kg' => 200_000]);
        Tank::create(['code' => 'CPO-02', 'name' => 'B', 'commodity' => Commodity::CPO, 'capacity_kg' => 1_000_000, 'current_volume_kg' => 700_000, 'active' => false]);

        Livewire::test(StockOverview::class)
            ->assertSee('200.0 t')
            ->assertSee('of 1,000.0 t capacity')
            ->assertDontSee('900.0 t');
    }

    public function test_loss_label_comes_from_the_one_loss_rate(): void
    {
        $this->assertSame('0.5%', TransitLossCalculator::percentLabel());
    }

    public function test_a_messy_duplicate_plate_is_caught_by_the_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Admin]));
        Truck::create(['plate' => 'KDA 214X']);

        Livewire::test(CreateTruck::class)
            ->fillForm(['plate' => 'kda   214x'])
            ->call('create')
            ->assertHasFormErrors(['plate']);

        $this->assertSame(1, Truck::count());
    }
}
