<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Fleet\Actions\TruckVisitWorkflow;
use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Filament\Resources\DeliveryOrderResource\Pages\RunDelivery;
use App\Filament\Resources\TruckVisitResource\Pages\ListTruckVisits;
use App\Filament\Resources\TruckVisitResource\Pages\ViewTruckVisit;
use App\Livewire\AlertsBell;
use App\Models\CargoMovement;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\TruckVisit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
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

        Livewire::test(RunDelivery::class, ['record' => $this->gate()['order_id']])
            ->call('startGateIn')
            ->set('gateInData.truck_id', $this->gate()['truck_id'])->set('gateInData.driver_id', $this->gate()['driver_id'])
            ->call('gateIn')
            ->assertNotified('Gated in with warnings');

        $v = TruckVisit::where('truck_id', Truck::where('plate', 'KDA 214X')->value('id'))->first();
        $this->assertSame(VisitStatus::Arrived, $v->status);
        $this->assertCount(2, $v->warnings);                                               // truck insurance + driver licence
        $this->assertStringContainsString('insurance expired', $v->warnings[0]);
    }

    public function test_a_truck_already_on_site_is_not_offered_again(): void
    {
        $this->login(Role::Operator);
        $order = $this->gate()['order_id'];

        Livewire::test(RunDelivery::class, ['record' => $order])
            ->set('gateInData.truck_id', Truck::where('plate', 'KCD 452M')->value('id'))     // already in the loading bay
            ->call('gateIn')->assertHasErrors(['gateInData.truck_id']);

        Livewire::test(RunDelivery::class, ['record' => $order])
            ->set('gateInData.truck_id', $this->gate()['truck_id'])
            ->set('gateInData.driver_id', Driver::where('name', 'Joseph Kamau')->value('id'))  // driver is on site
            ->call('gateIn')->assertHasErrors(['gateInData.driver_id']);
    }

    public function test_a_completed_order_cannot_take_more_trucks(): void
    {
        $this->login(Role::Operator);
        $done = DeliveryOrder::where('order_ref', 'ORD-1001')->value('id');                // completed

        Livewire::test(RunDelivery::class, ['record' => $done])
            ->assertDontSee('Gate in a truck')                                             // the wizard has no gate for a closed order
            ->set('gateInData.truck_id', $this->gate()['truck_id'])->call('gateIn')->assertNotified('Not done');   // and the domain refuses a crafted request
    }

    public function test_an_operator_runs_a_visit_from_gate_to_gate(): void
    {
        $this->login(Role::Operator);
        $visit = TruckVisit::where('status', VisitStatus::Arrived->value)->first();         // KCB 887L for ORD-1003 (loading)
        $cpol = Tank::where('code', 'CPOL-01')->value('id');
        $load = fn ($w, int $kg, string $key) => $w->set('loadData.tank_id', $cpol)->set('loadData.quantity_kg', $kg)
            ->set('loadData.occurred_at', now()->toDateTimeString())->set('loadData.idempotency_key', $key)->call('load');

        $w = Livewire::test(RunDelivery::class, ['record' => $visit->delivery_order_id])->call('selectVisit', $visit->id);

        $w->assertSee('Weigh the empty truck')->assertDontSee('Release the truck');
        $w->set('weighInData.kg', 15_000)->call('weighIn')->assertNotified('Weighed in');
        $w->call('startTruckLoading')->assertNotified('Loading started')->assertSee('Load from a tank')->assertDontSee('Weigh the empty truck');

        // Payload is 32,000 kg: this overload is refused with a clear message and books nothing.
        $load($w, 33_000, 'ui-v1')->assertNotified('Not done');
        $this->assertSame(0, $visit->fresh()->loadedKg());

        $load($w, 25_000, 'ui-v2')->assertNotified('Loaded');
        $this->assertSame(25_000, $visit->fresh()->loadedKg());

        $w->set('weighOutData.kg', 15_000 + 25_000)->call('weighOut')->assertNotified('Weighed out')->assertSee('Release the truck');
        $w->call('gateOut')->assertNotified('Truck has left site');

        $v = $visit->fresh();
        $this->assertSame(VisitStatus::Departed, $v->status);
        $this->assertNull($v->active_truck_id);
    }

    public function test_a_wrong_move_is_rejected_with_a_message(): void
    {
        $this->login(Role::Operator);
        $weighedIn = TruckVisit::where('status', VisitStatus::WeighedIn->value)->first();   // KDA 999Z for ORD-1004, which is still pending

        Livewire::test(RunDelivery::class, ['record' => $weighedIn->delivery_order_id])
            ->call('selectVisit', $weighedIn->id)->call('startTruckLoading')->assertNotified('Not done');   // "Start loading the order first"

        $this->assertSame(VisitStatus::WeighedIn, $weighedIn->fresh()->status);
    }

    public function test_cancelling_a_visit_frees_the_truck(): void
    {
        $this->login(Role::Operator);
        $v = TruckVisit::where('status', VisitStatus::Arrived->value)->first();

        Livewire::test(RunDelivery::class, ['record' => $v->delivery_order_id])
            ->call('selectVisit', $v->id)->callAction('cancel_visit')->assertNotified('Truck visit cancelled');

        $this->assertSame(VisitStatus::Cancelled, $v->fresh()->status);
        $this->assertFalse($v->truck->fresh()->activeVisit()->exists());
    }

    public function test_read_only_roles_see_no_gate_or_weighbridge_buttons(): void
    {
        $this->login(Role::Auditor);
        $v = TruckVisit::where('status', VisitStatus::Loading->value)->first();

        Livewire::test(ListTruckVisits::class)->assertActionHidden('gate_in');
        Livewire::test(ViewTruckVisit::class, ['record' => $v->id])->assertActionHidden('continue');

        Livewire::test(RunDelivery::class, ['record' => $v->delivery_order_id])->call('selectVisit', $v->id)
            ->assertSee('You can look at this order but not change it')->assertSee('Weigh out')
            ->set('weighOutData.kg', 30_000)->call('weighOut')->assertForbidden();
    }

    public function test_an_order_with_a_truck_on_site_cannot_be_completed_from_the_screen(): void
    {
        $this->login(Role::Operator);
        $order = DeliveryOrder::where('order_ref', 'ORD-1003')->first();                      // loading; two trucks on site, one load booked

        Livewire::test(RunDelivery::class, ['record' => $order->id])->call('finishOrder')->call('completeOrder')->assertNotified('Not done');

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

    public function test_the_bell_tells_each_role_what_it_can_act_on(): void
    {
        $this->login(Role::Operator);
        Livewire::test(AlertsBell::class)
            ->assertSee('KDA 999Z on site with warnings')                              // no document dates recorded
            ->assertSee('KCB 887L on site with warnings')                              // inspection expiring + driver licence expiring
            ->assertDontSee('Documents to chase');                                     // a registers task: not the operator's

        $this->login(Role::Supervisor);
        Livewire::test(AlertsBell::class)
            ->assertSee('KDA 999Z on site with warnings')
            ->assertSee('Documents to chase: 2 trucks and 2 drivers');                // trucks KDA 214X (expired), KCB 887L (soon); drivers Mary (soon), Peter (expired)
    }

    public function test_a_truck_that_overstays_is_flagged(): void
    {
        $this->login(Role::Operator);
        $v = TruckVisit::where('status', VisitStatus::WeighedIn->value)->first();
        $v->forceFill(['gate_in_at' => now()->subHours(13)])->saveQuietly();

        Livewire::test(AlertsBell::class)->assertSee('KDA 999Z on site for over 12 h');
    }
}
