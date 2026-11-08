<?php

namespace Tests\Feature;

use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Filament\Resources\DeliveryOrderResource\Pages\ListDeliveryOrders;
use App\Filament\Resources\DeliveryOrderResource\Pages\RunDelivery;
use App\Filament\Resources\VesselDischargeResource\Pages\ListVesselDischarges;
use App\Filament\Resources\VesselDischargeResource\Pages\RunDischarge;
use App\Filament\Widgets\InventoryTrend;
use App\Filament\Widgets\RecentMovements;
use App\Filament\Widgets\StockOverview;
use App\Filament\Widgets\TankGauges;
use App\Filament\Widgets\TrucksOnSite;
use App\Filament\Widgets\VesselsInPort;
use App\Livewire\AlertsBell;
use App\Models\CargoMovement;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\TruckVisit;
use App\Models\User;
use App\Models\VesselDischarge;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Boots every screen with seeded data and drives the real buttons, so UI wiring errors fail CI. */
class PanelPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', 'admin')->first());
    }

    public function test_every_screen_loads(): void
    {
        $tank = Tank::first();
        $vessel = VesselDischarge::first();
        $order = DeliveryOrder::first();

        foreach ([
            '/admin', '/admin/tanks', '/admin/tanks/create', "/admin/tanks/{$tank->id}", "/admin/tanks/{$tank->id}/edit",
            '/admin/cargo-movements',
            '/admin/vessel-discharges', '/admin/vessel-discharges/create', "/admin/vessel-discharges/{$vessel->id}",
            '/admin/delivery-orders', '/admin/delivery-orders/create', "/admin/delivery-orders/{$order->id}",
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_dashboard_widgets_render_real_data(): void
    {
        Livewire::test(TankGauges::class)->assertSee('CPO-01')->assertSee('PFAD-01')->assertSee('free');
        Livewire::test(AlertsBell::class)->assertSee('Needs attention')->assertSee('MV Borneo Trader');
        Livewire::test(RecentMovements::class)->assertSee('ORD-1002');
        Livewire::test(VesselsInPort::class)->assertSee('MV Borneo Trader')->assertSee('MV Kenya Star')->assertSee('B1')->assertSee('85%')->assertDontSee('MV Pacific Dawn');
        Livewire::test(TrucksOnSite::class)->assertSee('KCD 452M')->assertSee('KCB 887L')->assertSee('KDA 999Z')->assertSee('Joseph Kamau');
        Livewire::test(StockOverview::class)->assertSee('Transit loss');
        Livewire::test(InventoryTrend::class)->assertOk();
    }

    public function test_alerts_flag_near_full_and_overdue_items(): void
    {
        $t = Tank::where('code', 'PFAD-01')->first();      // 1.7M of 2M = 84.6%
        $this->assertStringNotContainsString('nearly full', (string) Livewire::test(AlertsBell::class)->html());

        $t->update(['current_volume_kg' => 1_900_000]);       // 95%
        Livewire::test(AlertsBell::class)->assertSee('PFAD-01 nearly full');
    }

    public function test_operator_can_run_a_vessel_through_the_wizard(): void
    {
        $v = VesselDischarge::where('status', DischargeStatus::Berthed->value)->first();   // VOY-2611-04, CPO
        $cpo = Tank::where('code', 'CPO-02')->first();

        Livewire::test(ListVesselDischarges::class)->assertActionVisible(TestAction::make('continue')->table($v));

        $wizard = Livewire::test(RunDischarge::class, ['record' => $v->id])
            ->assertSee('Start the discharge')
            ->call('start')->assertNotified('Discharge started');

        $wizard->assertSee('Pump into a tank')
            ->set('pumpData.tank_id', $cpo->id)->set('pumpData.quantity_kg', 1_000_000)
            ->set('pumpData.occurred_at', now()->toDateTimeString())->set('pumpData.idempotency_key', 'ui-1')
            ->call('pump')->assertNotified('Cargo pumped into tank');

        $this->assertSame(995_000, $cpo->fresh()->current_volume_kg);
        $this->assertSame($v->id, CargoMovement::where('idempotency_key', 'ui-1')->value('vessel_discharge_id'));
    }

    public function test_buttons_update_in_place_after_a_status_change(): void
    {
        $v = VesselDischarge::where('status', DischargeStatus::Berthed->value)->first();

        Livewire::test(RunDischarge::class, ['record' => $v->id])
            ->assertSee('Start the discharge')->assertDontSee('Pump into a tank')    // not discharging yet
            ->call('start')
            ->assertSee('Pump into a tank')                                          // the next step appears without a reload
            ->assertDontSee('Start the discharge');

        $o = DeliveryOrder::where('status', OrderStatus::Pending->value)->first();

        Livewire::test(RunDelivery::class, ['record' => $o->id])
            ->assertSee('Open loading')->assertDontSee('Take each truck')
            ->call('openLoading')
            ->assertSee('Take each truck')->assertDontSee('Open loading');
    }

    public function test_form_blocks_a_tank_holding_a_different_product(): void
    {
        $v = VesselDischarge::where('status', DischargeStatus::Discharging->value)->first();   // PFAD
        $cpo = Tank::where('code', 'CPO-02')->first();

        Livewire::test(RunDischarge::class, ['record' => $v->id])
            ->set('pumpData.tank_id', $cpo->id)->set('pumpData.quantity_kg', 10_000)
            ->set('pumpData.occurred_at', now()->toDateTimeString())->set('pumpData.idempotency_key', 'ui-2')
            ->call('pump')->assertHasErrors(['pumpData.tank_id']);

        $this->assertDatabaseMissing('cargo_movements', ['idempotency_key' => 'ui-2']);
    }

    public function test_overflow_from_the_form_shows_a_rejection_and_changes_nothing(): void
    {
        $v = VesselDischarge::where('status', DischargeStatus::Discharging->value)->first();   // PFAD, 300,000 kg left on B/L
        $pfad = Tank::where('code', 'PFAD-01')->first();
        $pfad->update(['opening_volume_kg' => 300_000]);       // ledger replay now ends at 1,991,500 kg

        Livewire::test(RunDischarge::class, ['record' => $v->id])
            ->set('pumpData.tank_id', $pfad->id)->set('pumpData.quantity_kg', 300_000)
            ->set('pumpData.occurred_at', now()->toDateTimeString())->set('pumpData.idempotency_key', 'ui-4')
            ->call('pump')->assertNotified('Not done');

        $this->assertDatabaseMissing('cargo_movements', ['idempotency_key' => 'ui-4']);
    }

    public function test_operator_can_run_a_delivery_order_through_the_wizard(): void
    {
        $o = DeliveryOrder::where('status', OrderStatus::Pending->value)->first();       // ORD-1004, PFAD 200,000
        $pfad = Tank::where('code', 'PFAD-01')->first();
        $visit = $o->visits()->first();                                                  // KDA 999Z, weighed in at the gate

        Livewire::test(ListDeliveryOrders::class)->assertActionVisible(TestAction::make('continue')->table($o));

        $w = Livewire::test(RunDelivery::class, ['record' => $o->id])
            ->call('openLoading')->assertNotified('Loading opened')
            ->call('selectVisit', $visit->id)->call('startTruckLoading')->assertNotified('Loading started');   // the truck moves to the loading bay

        $w->set('loadData.tank_id', $pfad->id)->set('loadData.quantity_kg', 20_000)
            ->set('loadData.occurred_at', now()->toDateTimeString())->set('loadData.idempotency_key', 'ui-3')
            ->call('load')->assertNotified('Loaded');

        $this->assertSame(180_000, DeliveryOrder::withTotals()->find($o->id)->remainingKg());
        $this->assertSame(20_000, $visit->fresh()->loadedKg());
    }

    public function test_seeder_is_idempotent_and_upgrades_an_old_demo_database(): void
    {
        $this->seed(DatabaseSeeder::class);                    // second run changes nothing
        $this->assertSame(5, VesselDischarge::count());
        $this->assertSame(4, DeliveryOrder::count());

        // Simulate the live database from before operations existed.
        CargoMovement::query()->update(['vessel_discharge_id' => null, 'delivery_order_id' => null]);
        TruckVisit::query()->delete();             // an old database has no visits either
        VesselDischarge::query()->delete();
        DeliveryOrder::query()->delete();
        $movements = CargoMovement::count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(5, VesselDischarge::count());
        $this->assertSame($movements, CargoMovement::count());           // ledger untouched
        $this->assertSame(
            VesselDischarge::where('voyage_ref', 'VOY-2611-01')->value('id'),
            CargoMovement::where('idempotency_key', 'seed-1')->value('vessel_discharge_id'),
        );
    }
}
