<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Cargo\Services\TransitLossCalculator;
use App\Filament\Resources\DeliveryOrderResource\Pages\RunDelivery;
use App\Filament\Resources\VesselDischargeResource\Pages\RunDischarge;
use App\Filament\Support\QuantityChips;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\TruckVisit;
use App\Models\VesselDischarge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/** Quick amounts under the quantity field: automatic, never over a limit, and safe to press. */
class QuantityChipsTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    public function test_amounts_are_rounded_down_to_a_step_that_suits_their_size(): void
    {
        foreach ([0 => 0, 999 => 0, 1_500 => 1_000, 19_999 => 19_000, 45_000 => 45_000, 123_456 => 120_000, 1_100_000 => 1_100_000, 2_345_678 => 2_300_000] as $in => $out) {
            $this->assertSame($out, QuantityChips::nice($in), (string) $in);
        }
    }

    public function test_the_largest_gross_that_fits_is_exact_after_the_transit_loss(): void
    {
        $loss = new TransitLossCalculator;

        foreach ([1, 1_000, 100_000, 1_203_015, 2_000_000] as $free) {
            $gross = QuantityChips::grossThatFits($free);

            $this->assertLessThanOrEqual($free, $gross - $loss->lossFor($gross), "{$free}: fits");
            $this->assertGreaterThan($free, ($gross + 1) - $loss->lossFor($gross + 1), "{$free}: one more would not");
        }

        $this->assertSame(0, QuantityChips::grossThatFits(0));
        $this->assertSame(0, QuantityChips::grossThatFits(-5));
    }

    public function test_the_smallest_limit_decides_and_no_chip_goes_over_it(): void
    {
        $chips = QuantityChips::from(['All remaining' => 2_200_000]);
        $this->assertSame(['Quarter', 'Half', 'All remaining'], array_column($chips, 'label'));
        $this->assertSame([550_000, 1_100_000, 2_200_000], array_column($chips, 'kg'));

        $chips = QuantityChips::from(['All remaining' => 2_200_000, 'Fill the tank' => 1_000_000]);
        $this->assertSame('Fill the tank', end($chips)['label']);
        $this->assertSame([250_000, 500_000, 1_000_000], array_column($chips, 'kg'));
        $this->assertLessThanOrEqual(1_000_000, max(array_column($chips, 'kg')));
    }

    public function test_empty_limits_give_no_chips_and_zero_limits_are_ignored(): void
    {
        $this->assertSame([], QuantityChips::from([]));
        $this->assertSame([], QuantityChips::from(['Fill the tank' => 0]));
        $this->assertSame([1_000, 2_000, 5_000], array_column(QuantityChips::from(['Fill the tank' => 0, 'Fill the truck' => 5_000]), 'kg'));
    }

    public function test_pump_chips_follow_the_chosen_tank_and_fill_the_field(): void
    {
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Operator);
        $voyage = VesselDischarge::where('voyage_ref', 'VOY-2611-03')->firstOrFail();           // PFAD, 300,000 kg still on the ship
        $pfad = Tank::where('code', 'PFAD-01')->firstOrFail();                                   // 100,000 kg of room in the edge scenario

        $wizard = Livewire::test(RunDischarge::class, ['record' => $voyage->id]);
        $labels = array_column($wizard->instance()->quantityChips(), 'label');
        $this->assertSame('All remaining', end($labels));                                        // no tank chosen yet

        $wizard->set('pumpData.tank_id', $pfad->id);
        $chips = $wizard->instance()->quantityChips();
        $fits = QuantityChips::grossThatFits($pfad->capacity_kg - $pfad->current_volume_kg);

        $this->assertSame('Fill the tank', end($chips)['label']);
        $this->assertSame($fits, end($chips)['kg']);
        $this->assertLessThan(300_000, $fits);

        $wizard->call('setQuantity', $fits)->assertSet('pumpData.quantity_kg', $fits)
            ->assertSee(number_format($fits).' kg')->assertSee('is lost in transit');
        $wizard->call('pump')->assertNotified('Cargo pumped into tank');                          // and the domain accepts the full amount
    }

    public function test_an_amount_that_is_not_on_offer_is_refused(): void
    {
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Operator);
        $voyage = VesselDischarge::where('voyage_ref', 'VOY-2611-03')->firstOrFail();

        Livewire::test(RunDischarge::class, ['record' => $voyage->id])->call('setQuantity', 123_457)->assertStatus(422);
    }

    public function test_a_read_only_role_cannot_press_a_chip(): void
    {
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Auditor);
        $voyage = VesselDischarge::where('voyage_ref', 'VOY-2611-03')->firstOrFail();

        Livewire::test(RunDischarge::class, ['record' => $voyage->id])->call('setQuantity', 300_000)->assertForbidden();
    }

    public function test_truck_chips_fill_the_truck_and_the_domain_accepts_exactly_that(): void
    {
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Operator);
        $order = DeliveryOrder::where('order_ref', 'ORD-1003')->firstOrFail();
        $visit = TruckVisit::whereHas('truck', fn ($q) => $q->where('plate', 'KCD 452M'))->latest('id')->firstOrFail();   // loading, payload 30,000 kg
        $cpol = Tank::where('code', 'CPOL-01')->firstOrFail();

        $wizard = Livewire::test(RunDelivery::class, ['record' => $order->id])->call('selectVisit', $visit->id)->set('loadData.tank_id', $cpol->id);
        $chips = $wizard->instance()->quantityChips();
        $room = $visit->truck->capacity_kg - $visit->loadedKg();

        $this->assertSame('Fill the truck', end($chips)['label']);
        $this->assertSame($room, end($chips)['kg']);

        $wizard->call('setQuantity', $room)->assertSet('loadData.quantity_kg', $room)->call('load')->assertNotified('Loaded');
        $this->assertSame($visit->truck->capacity_kg, $visit->fresh()->loadedKg());
    }

    public function test_weighbridge_fields_get_the_same_quick_buttons(): void
    {
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Operator);
        $order = DeliveryOrder::where('order_ref', 'ORD-1003')->firstOrFail();

        // Weigh-in: the truck's usual empty weight.
        $arrived = TruckVisit::whereHas('truck', fn ($q) => $q->where('plate', 'KCB 887L'))->latest('id')->firstOrFail();
        $arrived->truck->update(['tare_kg' => 14_200]);
        $wizard = Livewire::test(RunDelivery::class, ['record' => $order->id])->call('selectVisit', $arrived->id);
        $this->assertSame([['label' => 'Usual empty weight', 'kg' => 14_200]], $wizard->instance()->weightChips('in'));
        $wizard->assertSee('Usual empty weight')->call('setWeight', 'in', 14_200)->assertSet('weighInData.kg', 14_200)
            ->call('weighIn')->assertNotified('Weighed in');

        // Weigh-out: empty plus what was loaded.
        $loading = TruckVisit::whereHas('truck', fn ($q) => $q->where('plate', 'KCD 452M'))->latest('id')->firstOrFail();
        $cpol = Tank::where('code', 'CPOL-01')->firstOrFail();
        $wizard = Livewire::test(RunDelivery::class, ['record' => $order->id])->call('selectVisit', $loading->id)->set('loadData.tank_id', $cpol->id);
        $this->assertSame([], $wizard->instance()->weightChips('out'));                              // nothing loaded onto this truck yet
        $wizard->call('setQuantity', $wizard->instance()->quantityChips()[0]['kg'])->call('load')->assertNotified('Loaded');
        $expected = $loading->weigh_in_kg + $loading->fresh()->loadedKg();
        $this->assertSame([['label' => 'Empty + loaded', 'kg' => $expected]], $wizard->instance()->weightChips('out'));
        $wizard->call('setWeight', 'out', $expected)->assertSet('weighOutData.kg', $expected)->call('weighOut')->assertNotified('Weighed out');
    }

    public function test_a_weight_that_is_not_on_offer_is_refused_and_read_only_roles_cannot_press_it(): void
    {
        $this->seedWithEdgeStates();
        $order = DeliveryOrder::where('order_ref', 'ORD-1003')->firstOrFail();

        $this->loginAs(Role::Operator);
        Livewire::test(RunDelivery::class, ['record' => $order->id])->call('setWeight', 'in', 1)->assertStatus(422);

        $this->loginAs(Role::Auditor);
        Livewire::test(RunDelivery::class, ['record' => $order->id])->call('setWeight', 'in', 1)->assertForbidden();
    }

    public function test_finish_the_order_is_called_out_once_everything_is_loaded(): void
    {
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Operator);
        $order = DeliveryOrder::where('order_ref', 'ORD-1003')->firstOrFail();

        Livewire::test(RunDelivery::class, ['record' => $order->id])->assertDontSee('is loaded.');   // still 250,000 kg to go

        $order->update(['ordered_kg' => $order->deliveredKg()]);                                      // now everything ordered is loaded
        Livewire::test(RunDelivery::class, ['record' => $order->id])
            ->assertSee('is loaded.')->assertSee('then finish the order')->assertSee('bs-finish', false);

        TruckVisit::where('delivery_order_id', $order->id)->update(['status' => 'departed', 'active_truck_id' => null]);        // and every truck has left
        Livewire::test(RunDelivery::class, ['record' => $order->id])
            ->assertSee('No trucks are left on site. Finish the order to close it.')->assertSee('Finish the order');
    }

    public function test_the_wizards_carry_the_phone_first_markup(): void
    {
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Operator);

        Livewire::test(RunDischarge::class, ['record' => VesselDischarge::where('voyage_ref', 'VOY-2611-03')->value('id')])
            ->assertSee('Step 3 of 4')->assertSee('More figures')->assertSee('bs-secondary', false);
        Livewire::test(RunDelivery::class, ['record' => DeliveryOrder::where('order_ref', 'ORD-1003')->value('id')])
            ->assertSee('Step 2 of 3')->assertSee('More figures')->assertSee('bs-secondary', false);
    }
}
