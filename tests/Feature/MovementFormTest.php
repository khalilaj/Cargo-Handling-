<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Filament\Resources\CargoMovementResource\Pages\ListCargoMovements;
use App\Filament\Support\MovementForm;
use App\Models\CargoMovement;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\Vessel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/** The quick-entry buttons pick the vessel / truck from the registers; typed text is not accepted. */
class MovementFormTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Operator);
    }

    private function data(string $reference, string $tank, string $key): array
    {
        return ['tank_id' => Tank::where('code', $tank)->value('id'), 'quantity_kg' => 1000, 'occurred_at' => now()->toDateTimeString(), 'reference' => $reference, 'idempotency_key' => $key];
    }

    public function test_discharge_takes_a_registered_vessel(): void
    {
        $before = CargoMovement::count();
        Livewire::test(ListCargoMovements::class)
            ->callAction('discharge', $this->data(Vessel::where('active', true)->value('name'), 'CPO-02', 'mf-ok'))
            ->assertHasNoActionErrors();
        $this->assertSame($before + 1, CargoMovement::count());
    }

    public function test_discharge_refuses_typed_or_retired_vessels(): void
    {
        $before = CargoMovement::count();
        foreach (['Typed Nonsense', Vessel::where('active', false)->value('name')] as $i => $name) {
            Livewire::test(ListCargoMovements::class)
                ->callAction('discharge', $this->data($name, 'CPO-02', "mf-bad-$i"))
                ->assertHasActionErrors(['reference']);
        }
        $this->assertSame($before, CargoMovement::count());
    }

    public function test_delivery_takes_a_registered_truck_only(): void
    {
        $plate = Truck::where('active', true)->value('plate');
        Livewire::test(ListCargoMovements::class)
            ->callAction('delivery', $this->data($plate, 'CPO-01', 'mf-truck'))
            ->assertHasNoActionErrors();
        Livewire::test(ListCargoMovements::class)
            ->callAction('delivery', $this->data('KXX 000X', 'CPO-01', 'mf-truck-bad'))
            ->assertHasActionErrors(['reference']);
        Livewire::test(ListCargoMovements::class)
            ->callAction('delivery', $this->data(Truck::where('active', false)->value('plate'), 'CPO-01', 'mf-truck-old'))
            ->assertHasActionErrors(['reference']);
    }

    public function test_the_party_is_required(): void
    {
        Livewire::test(ListCargoMovements::class)
            ->callAction('discharge', $this->data('', 'CPO-02', 'mf-empty'))
            ->assertHasActionErrors(['reference' => 'required']);
    }

    public function test_choosing_the_tank_fills_in_the_vessel_or_truck_working_on_that_product(): void
    {
        $pfad = Tank::where('code', 'PFAD-01')->value('id');      // MV Borneo Trader is discharging PFAD
        $cpo2 = Tank::where('code', 'CPO-02')->value('id');       // nobody is working on CPO right now
        $cpol = Tank::where('code', 'CPOL-01')->value('id');      // KCD 452M is loading CPOL

        $this->assertSame('MV Borneo Trader', MovementForm::suggest('vessel', $pfad));
        $this->assertNull(MovementForm::suggest('vessel', $cpo2));
        $this->assertSame('KCD 452M', MovementForm::suggest('truck', $cpol));
        $this->assertNull(MovementForm::suggest('truck', $cpo2));
        $this->assertNull(MovementForm::suggest('vessel', 0));
    }

    public function test_the_form_applies_the_suggestion_when_a_tank_is_chosen(): void
    {
        Livewire::test(ListCargoMovements::class)
            ->mountAction('discharge')
            ->fillForm(['tank_id' => Tank::where('code', 'PFAD-01')->value('id')])
            ->assertSchemaStateSet(['reference' => 'MV Borneo Trader']);
    }
}
