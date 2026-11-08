<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Operations\Actions\AdvanceOrderStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Filament\Resources\DeliveryOrderResource;
use App\Filament\Resources\DeliveryOrderResource\Pages\CreateDeliveryOrder;
use App\Filament\Resources\DeliveryOrderResource\Pages\RunDelivery;
use App\Filament\Resources\DeliveryOrderResource\Pages\ViewDeliveryOrder;
use App\Filament\Resources\TruckVisitResource\Pages\ListTruckVisits;
use App\Filament\Resources\TruckVisitResource\Pages\ViewTruckVisit;
use App\Models\CargoMovement;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\TruckVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/** The delivery wizard: one page per order, with each truck's steps inside it. */
class DeliveryWizardTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Operator);
    }

    private function order(string $ref): DeliveryOrder
    {
        return DeliveryOrder::where('order_ref', $ref)->firstOrFail();
    }

    private function visitOf(string $plate): TruckVisit
    {
        return TruckVisit::whereHas('truck', fn ($q) => $q->where('plate', $plate))->latest('id')->firstOrFail();
    }

    private function wizard(DeliveryOrder $o)
    {
        return Livewire::test(RunDelivery::class, ['record' => $o->id]);
    }

    public function test_it_opens_on_the_current_step_for_every_status(): void
    {
        foreach ([
            'ORD-1004' => 'Open loading',          // pending
            'ORD-1003' => 'Take each truck',       // loading
            'ORD-1001' => 'Order complete',        // completed
            'ORD-EDGE-1' => 'Order cancelled',     // cancelled
        ] as $ref => $text) {
            $this->wizard($this->order($ref))->assertOk()->assertSee($text);
        }
    }

    public function test_a_truck_goes_from_the_gate_to_the_gate_out_inside_the_order_page(): void
    {
        $order = $this->order('ORD-1003');                                   // loading, CPOL
        $truck = Truck::create(['plate' => 'KWIZ 1A', 'capacity_kg' => 30_000, 'tare_kg' => 14_000, 'insurance_expiry' => '2027-06-30', 'inspection_expiry' => '2027-06-30']);
        $cpol = Tank::where('code', 'CPOL-01')->firstOrFail();

        $w = $this->wizard($order)->call('startGateIn')->assertSee('Gate in a truck')
            ->set('gateInData.truck_id', $truck->id)->call('gateIn')->assertNotified('Truck gated in')
            ->assertSee('Weigh the empty truck');
        $visit = $this->visitOf('KWIZ 1A');
        $this->assertSame(VisitStatus::Arrived, $visit->status);

        $w->set('weighInData.kg', 14_000)->call('weighIn')->assertNotified('Weighed in')->assertSee('Move the truck into the loading bay');
        $w->call('startTruckLoading')->assertNotified('Loading started')->assertSee('Load from a tank');

        $w->set('loadData.tank_id', $cpol->id)->set('loadData.quantity_kg', 10_000)
            ->set('loadData.occurred_at', now()->toDateTimeString())->set('loadData.idempotency_key', 'dw-1')
            ->call('load')->assertNotified('Loaded')->assertSee('Loaded onto this truck');
        $this->assertSame(10_000, $visit->fresh()->loadedKg());

        $w->set('weighOutData.kg', 24_000)->call('weighOut')->assertNotified('Weighed out')->assertSee('Release the truck');
        $w->call('gateOut')->assertNotified('Truck has left site');
        $this->assertSame(VisitStatus::Departed, $visit->fresh()->status);
    }

    public function test_an_order_runs_from_open_loading_to_complete(): void
    {
        $order = $this->order('ORD-1004');                                   // pending, PFAD, KDA 999Z weighed in at the gate
        $pfad = Tank::where('code', 'PFAD-01')->firstOrFail();

        $w = $this->wizard($order)->call('openLoading')->assertNotified('Loading opened')->assertSee('Take each truck');
        $this->assertSame(OrderStatus::Loading, $order->fresh()->status);

        $w->call('startTruckLoading')->assertNotified('Loading started');
        $w->set('loadData.tank_id', $pfad->id)->set('loadData.quantity_kg', 20_000)
            ->set('loadData.occurred_at', now()->toDateTimeString())->set('loadData.idempotency_key', 'dw-2')->call('load')->assertNotified('Loaded');
        $w->set('weighOutData.kg', 33_500)->call('weighOut')->call('gateOut');

        $w->assertDontSee('Complete the order')->assertSee('Close the order short');       // 30,000 of 200,000 kg: not all loaded, nobody at the gate
        $order->update(['ordered_kg' => $order->deliveredKg()]);                            // everything ordered is now loaded
        $w->call('$refresh')->assertSee('Complete the order')->assertDontSee('Close the order short')->call('completeOrder')->assertNotified('Order completed');
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $w->assertSee('Order complete');
    }

    public function test_an_order_with_trucks_on_site_cannot_be_completed_and_says_which(): void
    {
        $order = $this->order('ORD-1003');                                   // KCD 452M is loading, KCB 887L has arrived

        $this->wizard($order)->assertDontSee('Complete the order')->call('completeOrder')->assertNotified('Not done');
        $this->assertSame(OrderStatus::Loading, $order->fresh()->status);
    }

    public function test_a_truck_cannot_take_more_than_its_payload_and_nothing_is_booked(): void
    {
        $order = $this->order('ORD-1003');
        $loading = $this->visitOf('KCD 452M');                               // payload 30,000 kg
        $before = CargoMovement::count();

        $this->wizard($order)->call('selectVisit', $loading->id)->assertSee('Load from a tank')
            ->set('loadData.tank_id', Tank::where('code', 'CPOL-01')->value('id'))->set('loadData.quantity_kg', 31_000)
            ->set('loadData.occurred_at', now()->toDateTimeString())->set('loadData.idempotency_key', 'dw-3')->call('load')
            ->assertNotified('Not done');

        $this->assertSame($before, CargoMovement::count());
    }

    public function test_choosing_a_truck_shows_that_trucks_step(): void
    {
        $order = $this->order('ORD-1003');

        $this->wizard($order)->call('selectVisit', $this->visitOf('KCB 887L')->id)->assertSee('Weigh the empty truck')
            ->call('selectVisit', $this->visitOf('KCD 452M')->id)->assertSee('Load from a tank');
    }

    public function test_an_auditor_can_look_but_not_press_anything(): void
    {
        $this->loginAs(Role::Auditor);
        $order = $this->order('ORD-1004');

        $this->wizard($order)->assertSee('You can look at this order but not change it')->assertSee('Start loading');   // same step, switched off
        $this->wizard($order)->call('openLoading')->assertForbidden();
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_a_step_taken_elsewhere_lands_on_the_real_current_step(): void
    {
        $order = $this->order('ORD-1004');
        $w = $this->wizard($order);                                          // this tab still shows "Open loading"

        app(AdvanceOrderStatus::class)($order->fresh(), OrderStatus::Loading);   // a colleague opened it first

        $w->call('openLoading')->assertNotified('Not done')->assertSee('Take each truck');
    }

    public function test_cancelling_keeps_its_confirmation_and_the_domain_rule(): void
    {
        $this->wizard($this->order('ORD-1003'))->callAction('cancel_order')->assertNotified('Not done');   // trucks on site, cargo loaded

        $pending = DeliveryOrder::create(['order_ref' => 'ORD-WIZ-1', 'customer_id' => Customer::first()->id, 'commodity' => Commodity::CPOL, 'ordered_kg' => 5_000]);
        $this->wizard($pending)->callAction('cancel_order')->assertNotified('Order cancelled');
        $this->assertSame(OrderStatus::Cancelled, $pending->fresh()->status);
    }

    public function test_every_forward_button_leads_to_the_wizard_instead_of_a_window(): void
    {
        $order = $this->order('ORD-1003');
        $visit = $this->visitOf('KCD 452M');
        $run = DeliveryOrderResource::getUrl('run', ['record' => $order]);

        Livewire::test(ViewDeliveryOrder::class, ['record' => $order->id])->assertActionVisible('continue')->assertActionHasUrl('continue', $run);
        Livewire::test(ViewTruckVisit::class, ['record' => $visit->id])->assertActionVisible('continue')
            ->assertActionHasUrl('continue', DeliveryOrderResource::getUrl('run', ['record' => $order, 'visit' => $visit->id]));
        Livewire::test(ListTruckVisits::class)->assertActionHasUrl('gate_in', DeliveryOrderResource::getUrl());

        $this->get($run)->assertOk();
        $this->get(DeliveryOrderResource::getUrl('run', ['record' => $order, 'visit' => $visit->id]))->assertOk()->assertSee('Load from a tank');
    }

    public function test_scheduling_an_order_opens_the_wizard_on_its_first_step(): void
    {
        $this->loginAs(Role::Supervisor);

        $page = Livewire::test(CreateDeliveryOrder::class)
            ->fillForm(['customer_id' => Customer::where('active', true)->value('id'), 'commodity' => Commodity::CPOL->value, 'ordered_kg' => 100_000])
            ->call('create')->assertHasNoFormErrors();

        $new = DeliveryOrder::latest('id')->first();
        $this->assertSame(OrderStatus::Pending, $new->status);
        $page->assertRedirect(DeliveryOrderResource::getUrl('run', ['record' => $new]));
    }

    public function test_every_role_sees_the_same_process(): void
    {
        $loading = $this->visitOf('KCD 452M');

        foreach (Role::cases() as $role) {
            $this->loginAs($role);

            $this->wizard($this->order('ORD-1004'))->assertSee('Open loading')->assertSee('Start loading');
            $this->wizard($this->order('ORD-1003'))->assertSee('Trucks')->assertSee('Gate in another truck')->assertDontSee('Finish the order')->assertDontSee('Complete the order')
                ->call('selectVisit', $loading->id)->assertSee('Load from a tank')->assertSee('Weigh out');
            $this->wizard($this->order('ORD-1001'))->assertSee('Order complete');
        }
    }
}
