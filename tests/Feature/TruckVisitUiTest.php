<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Fleet\Actions\TruckVisitWorkflow;
use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Filament\Resources\DeliveryOrderResource\Pages\ListDeliveryOrders;
use App\Filament\Resources\TruckVisitResource\Pages\ListTruckVisits;
use App\Filament\Resources\TruckVisitResource\Pages\ViewTruckVisit;
use App\Filament\Widgets\AlertsPanel;
use App\Models\CargoMovement;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\TruckVisit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class TruckVisitUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-05 10:00'));     // the demo document dates are fixed, so the clock is too
        $this->seed(DatabaseSeeder::class);
    }

    private function login(Role $role): User
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($u = User::where('role', $role->value)->firstOrFail());

        return $u;
    }

    private function gate(array $data = []): array
    {
        return array_replace([
            'order_id' => DeliveryOrder::where('order_ref', 'ORD-1003')->value('id'),
            'truck_id' => Truck::where('plate', 'KDA 214X')->value('id'),           // insurance expired
            'driver_id' => Driver::where('name', 'Peter Otieno')->value('id'),      // licence expired
        ], $data);
    }

    public function test_the_demo_has_trucks_at_different_stages(): void
    {
        $this->assertSame(
            ['arrived' => 1, 'loading' => 1, 'weighed_in' => 1],
            TruckVisit::whereNotNull('active_truck_id')->get()->countBy(fn ($v) => $v->status->value)->sortKeys()->all(),
        );
        $this->assertSame(0, CargoMovement::whereNotNull('truck_visit_id')->count());       // no stock moved by the demo
    }

    public function test_every_visit_screen_loads_for_every_role(): void
    {
        $id = TruckVisit::first()->id;

        foreach (Role::cases() as $role) {
            $this->login($role);
            $this->get('/admin/truck-visits')->assertOk();
            $this->get("/admin/truck-visits/{$id}")->assertOk();
        }
    }

    public function test_gating_in_a_truck_with_expired_documents_warns_and_admits(): void
    {
        $this->login(Role::Operator);

        Livewire::test(ListTruckVisits::class)
            ->callAction('gate_in', data: $this->gate())
            ->assertNotified('Gated in with warnings');

        $v = TruckVisit::where('truck_id', Truck::where('plate', 'KDA 214X')->value('id'))->first();
        $this->assertSame(VisitStatus::Arrived, $v->status);
        $this->assertCount(2, $v->warnings);                                               // truck insurance + driver licence
        $this->assertStringContainsString('insurance expired', $v->warnings[0]);
    }

    public function test_a_truck_already_on_site_is_not_offered_again(): void
    {
        $this->login(Role::Operator);

        Livewire::test(ListTruckVisits::class)
            ->callAction('gate_in', data: $this->gate(['truck_id' => Truck::where('plate', 'KCD 452M')->value('id')]))     // already in the loading bay
            ->assertHasActionErrors(['truck_id']);

        Livewire::test(ListTruckVisits::class)
            ->callAction('gate_in', data: $this->gate(['driver_id' => Driver::where('name', 'Joseph Kamau')->value('id')]))  // driver is on site
            ->assertHasActionErrors(['driver_id']);
    }

    public function test_the_gate_only_offers_orders_that_can_still_take_trucks(): void
    {
        $this->login(Role::Operator);
        $done = DeliveryOrder::where('order_ref', 'ORD-1001')->value('id');                // completed

        Livewire::test(ListTruckVisits::class)->callAction('gate_in', data: $this->gate(['order_id' => $done]))->assertHasActionErrors(['order_id']);
    }

    public function test_an_operator_runs_a_visit_from_gate_to_gate(): void
    {
        $this->login(Role::Operator);
        $visit = TruckVisit::where('status', VisitStatus::Arrived->value)->first();         // KCB 887L for ORD-1003 (loading)
        $cpol = Tank::where('code', 'CPOL-01')->value('id');

        $page = fn () => Livewire::test(ViewTruckVisit::class, ['record' => $visit->id]);

        $page()->assertActionHidden('start_loading')->assertActionHidden('weigh_out')->assertActionVisible('weigh_in');
        $page()->callAction('weigh_in', data: ['kg' => 15_000])->assertNotified('Weighed in');
        $page()->callAction('start_loading')->assertNotified('Loading started');
        $page()->assertActionVisible('load_visit')->assertActionHidden('weigh_in');

        // Payload is 32,000 kg: this overload is refused with a clear message and books nothing.
        $page()->callAction('load_visit', data: ['tank_id' => $cpol, 'quantity_kg' => 33_000, 'occurred_at' => now()->toDateTimeString(), 'idempotency_key' => 'ui-v1'])
            ->assertNotified('Rejected');
        $this->assertSame(0, $visit->fresh()->loadedKg());

        $page()->callAction('load_visit', data: ['tank_id' => $cpol, 'quantity_kg' => 25_000, 'occurred_at' => now()->toDateTimeString(), 'idempotency_key' => 'ui-v2'])
            ->assertNotified('Loaded');
        $this->assertSame(25_000, $visit->fresh()->loadedKg());

        $page()->callAction('weigh_out', data: ['kg' => 15_000 + 25_000])->assertNotified('Weighed out');
        $page()->callAction('gate_out')->assertNotified('Truck has left site');

        $v = $visit->fresh();
        $this->assertSame(VisitStatus::Departed, $v->status);
        $this->assertNull($v->active_truck_id);
        $page()->assertActionHidden('gate_out')->assertActionHidden('cancel_visit');
    }

    public function test_a_wrong_move_is_rejected_with_a_message(): void
    {
        $this->login(Role::Operator);
        $weighedIn = TruckVisit::where('status', VisitStatus::WeighedIn->value)->first();   // KDA 999Z for ORD-1004, which is still pending

        Livewire::test(ViewTruckVisit::class, ['record' => $weighedIn->id])
            ->callAction('start_loading')->assertNotified('Rejected');                         // "Start loading the order first"

        $this->assertSame(VisitStatus::WeighedIn, $weighedIn->fresh()->status);
    }

    public function test_cancelling_a_visit_frees_the_truck(): void
    {
        $this->login(Role::Operator);
        $v = TruckVisit::where('status', VisitStatus::Arrived->value)->first();

        Livewire::test(ListTruckVisits::class)->callAction(TestAction::make('cancel_visit')->table($v))->assertNotified('Visit cancelled');

        $this->assertSame(VisitStatus::Cancelled, $v->fresh()->status);
        $this->assertFalse($v->truck->fresh()->activeVisit()->exists());
    }

    public function test_read_only_roles_see_no_gate_or_weighbridge_buttons(): void
    {
        $this->login(Role::Auditor);
        $v = TruckVisit::where('status', VisitStatus::Loading->value)->first();

        Livewire::test(ListTruckVisits::class)->assertActionHidden('gate_in');
        Livewire::test(ViewTruckVisit::class, ['record' => $v->id])
            ->assertActionHidden('load_visit')->assertActionHidden('weigh_out')->assertActionHidden('weigh_in');
    }

    public function test_an_order_with_a_truck_on_site_cannot_be_completed_from_the_screen(): void
    {
        $this->login(Role::Operator);
        $order = DeliveryOrder::where('order_ref', 'ORD-1003')->first();                      // loading; two trucks on site, one load booked

        Livewire::test(ListDeliveryOrders::class)
            ->callAction(TestAction::make('to_completed')->table($order))->assertNotified('Rejected');

        $this->assertSame(OrderStatus::Loading, $order->fresh()->status);
    }

    public function test_the_list_shows_trucks_on_site_by_default(): void
    {
        $this->login(Role::Operator);
        $left = TruckVisit::where('status', VisitStatus::Arrived->value)->first();
        app(TruckVisitWorkflow::class)->cancel($left);

        Livewire::test(ListTruckVisits::class)
            ->assertSee('KCD 452M')->assertDontSee($left->truck->plate)                          // cancelled: gone from the on-site list
            ->removeTableFilter('on_site')->assertSee($left->truck->plate);
    }

    public function test_the_dashboard_alerts_cover_trucks_and_documents(): void
    {
        $this->login(Role::Operator);

        Livewire::test(AlertsPanel::class)
            ->assertSee('KDA 999Z on site with warnings')                              // no document dates recorded
            ->assertSee('KCB 887L on site with warnings')                              // inspection expiring + driver licence expiring
            ->assertSee('Documents to chase: 2 trucks and 2 drivers');                 // trucks KDA 214X (expired), KCB 887L (soon); drivers Mary (soon), Peter (expired)
    }

    public function test_a_truck_that_overstays_is_flagged(): void
    {
        $this->login(Role::Operator);
        $v = TruckVisit::where('status', VisitStatus::WeighedIn->value)->first();
        $v->forceFill(['gate_in_at' => now()->subHours(13)])->saveQuietly();

        Livewire::test(AlertsPanel::class)->assertSee('KDA 999Z on site for over 12 h');
    }
}
