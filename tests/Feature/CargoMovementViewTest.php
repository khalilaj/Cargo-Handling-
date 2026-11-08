<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Operations\Actions\RecordTruckLoad;
use App\Filament\Resources\CargoMovementResource;
use App\Filament\Resources\CargoMovementResource\Pages\ViewCargoMovement;
use App\Filament\Resources\DeliveryOrderResource;
use App\Filament\Resources\TruckVisitResource;
use App\Filament\Resources\VesselDischargeResource;
use App\Models\CargoMovement;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\TruckVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/** The read-only "View cargo movement" page: the story of one ledger row in plain words. */
class CargoMovementViewTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    private function plain(string $type, bool $withVisit = false): CargoMovement
    {
        return CargoMovement::query()->where('type', $type)->whereNull('reverses_movement_id')
            ->whereDoesntHave('reversal')
            ->when($withVisit, fn ($q) => $q->whereNotNull('truck_visit_id'), fn ($q) => $q->whereNotNull('vessel_discharge_id'))
            ->with(['tank', 'vesselDischarge', 'truckVisit.truck', 'deliveryOrder'])->firstOrFail();
    }

    public function test_an_inbound_movement_tells_where_it_came_from_and_where_it_is_now(): void
    {
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Operator);
        $m = $this->plain('vessel_discharge');
        $tank = $m->tank;

        Livewire::test(ViewCargoMovement::class, ['record' => $m->id])
            ->assertSuccessful()
            ->assertSee('Where the cargo went')->assertSee('Where it is now')
            ->assertSee($m->vesselDischarge->voyage_ref)
            ->assertSee(VesselDischargeResource::getUrl('view', ['record' => $m->vessel_discharge_id]), false)
            ->assertSee('Pumped into tank '.$tank->code)
            ->assertSee('In tank '.$tank->code.', which holds '.number_format($tank->current_volume_kg / 1000, 1).' t of '.number_format($tank->capacity_kg / 1000, 1).' t')
            ->assertSee(number_format($m->net_kg).' kg')
            ->assertDontSee('cancelled by a correction');
    }

    public function test_an_outbound_movement_names_the_truck_the_order_and_where_the_truck_is(): void
    {
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Operator);
        $order = DeliveryOrder::where('order_ref', 'ORD-1003')->firstOrFail();
        $visit = TruckVisit::whereHas('truck', fn ($q) => $q->where('plate', 'KCD 452M'))->latest('id')->firstOrFail();   // still loading
        $cpol = Tank::where('code', 'CPOL-01')->firstOrFail();
        $m = app(RecordTruckLoad::class)($order, $cpol->id, 5_000, now(), 'view-test-load', null, $visit);

        Livewire::test(ViewCargoMovement::class, ['record' => $m->id])
            ->assertSuccessful()
            ->assertSee('Taken from tank CPOL-01')
            ->assertSee('Loaded onto truck KCD 452M')
            ->assertSee(TruckVisitResource::getUrl('view', ['record' => $visit->id]), false)
            ->assertSee('ORD-1003')
            ->assertSee(DeliveryOrderResource::getUrl('view', ['record' => $order->id]), false)
            ->assertSee('On truck KCD 452M, still at the loading bay.');

        // Once the truck has left, the same row reads as delivered.
        $visit->update(['status' => 'departed', 'active_truck_id' => null]);
        Livewire::test(ViewCargoMovement::class, ['record' => $m->id])
            ->assertSee('Delivered to '.$order->customer.', truck left the site.');
    }

    public function test_a_reversal_and_the_movement_it_cancels_point_at_each_other(): void
    {
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Operator);
        $reversal = CargoMovement::whereNotNull('reverses_movement_id')->firstOrFail();
        $original = $reversal->reverses;

        Livewire::test(ViewCargoMovement::class, ['record' => $reversal->id])
            ->assertSuccessful()
            ->assertSee('This entry cancels movement #'.$original->id)
            ->assertSee(CargoMovementResource::getUrl('view', ['record' => $original->id]), false);

        Livewire::test(ViewCargoMovement::class, ['record' => $original->id])
            ->assertSuccessful()
            ->assertSee('Cancelled by a correction')
            ->assertSee(CargoMovementResource::getUrl('view', ['record' => $reversal->id]), false);
    }

    public function test_a_pending_correction_is_called_out(): void
    {
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Operator);
        $m = $this->plain('vessel_discharge');
        $m->correctionRequests()->create([
            'reason' => 'Quantity looks wrong to me.', 'status' => 'pending', 'requested_by' => auth()->id(),
        ]);

        Livewire::test(ViewCargoMovement::class, ['record' => $m->id])->assertSee('waiting for a supervisor');
    }

    public function test_the_movements_list_rows_open_the_page_and_keep_the_correct_action(): void
    {
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Operator);
        $m = $this->plain('vessel_discharge');

        $list = Livewire::test(CargoMovementResource\Pages\ListCargoMovements::class)
            ->assertTableActionExists('request_correction', null, $m)
            ->assertTableActionVisible('request_correction', $m);

        $this->assertSame(CargoMovementResource::getUrl('view', ['record' => $m->id]), $list->instance()->getTable()->getRecordUrl($m));
    }

    public function test_an_auditor_can_view_it(): void
    {
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Auditor);
        $m = $this->plain('vessel_discharge');

        Livewire::test(ViewCargoMovement::class, ['record' => $m->id])->assertSuccessful()->assertSee('Where the cargo went');
        $this->get(CargoMovementResource::getUrl('view', ['record' => $m->id]))->assertOk();
    }
}
