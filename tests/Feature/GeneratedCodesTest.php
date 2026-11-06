<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Operations\Services\CodeGenerator;
use App\Filament\Resources\BerthResource\Pages\CreateBerth;
use App\Filament\Resources\DeliveryOrderResource\Pages\CreateDeliveryOrder;
use App\Filament\Resources\TankResource\Pages\CreateTank;
use App\Filament\Resources\VesselDischargeResource\Pages\CreateVesselDischarge;
use App\Models\Berth;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\Vessel;
use App\Models\VesselDischarge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/** Nobody types a voyage, order, tank or berth number: the system numbers them, and never reuses a number. */
class GeneratedCodesTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    public function test_the_generator_continues_from_the_highest_number_in_use(): void
    {
        $this->seedDemo();
        $g = app(CodeGenerator::class);

        $this->assertSame('VOY-2611-06', $g->voyage());              // demo has VOY-2611-01..05
        $this->assertSame('ORD-1005', $g->order());                  // demo has ORD-1001..1004
        $this->assertSame('CPO-03', $g->tank(Commodity::CPO));       // CPO-01, CPO-02 exist
        $this->assertSame('PFAD-02', $g->tank(Commodity::PFAD));
        $this->assertMatchesRegularExpression('/^B\d+$/', $g->berth());
    }

    public function test_an_empty_install_starts_at_the_beginning(): void
    {
        $this->seedUsersOnly();
        $g = app(CodeGenerator::class);

        $this->assertSame('VOY-2611-01', $g->voyage());
        $this->assertSame('ORD-1001', $g->order());
        $this->assertSame('CPOL-01', $g->tank(Commodity::CPOL));
        $this->assertSame('B1', $g->berth());
    }

    public function test_gaps_and_other_shapes_never_cause_a_reused_number(): void
    {
        $this->seedUsersOnly();
        $vessel = Vessel::create(['name' => 'MV Gap', 'active' => true]);
        VesselDischarge::create(['voyage_ref' => 'VOY-2611-07', 'vessel_id' => $vessel->id, 'commodity' => Commodity::CPO, 'bl_quantity_kg' => 1000]);
        VesselDischarge::create(['voyage_ref' => 'LEGACY-A', 'vessel_id' => $vessel->id, 'commodity' => Commodity::CPO, 'bl_quantity_kg' => 1000]);

        $this->assertSame('VOY-2611-08', app(CodeGenerator::class)->voyage());
    }

    public function test_creating_records_without_a_code_numbers_them(): void
    {
        $this->seedDemo();
        $vessel = Vessel::where('active', true)->first();

        $a = VesselDischarge::create(['vessel_id' => $vessel->id, 'commodity' => Commodity::CPO, 'bl_quantity_kg' => 1000]);
        $b = VesselDischarge::create(['vessel_id' => $vessel->id, 'commodity' => Commodity::CPO, 'bl_quantity_kg' => 1000]);

        $this->assertSame('VOY-2611-06', $a->voyage_ref);
        $this->assertSame('VOY-2611-07', $b->voyage_ref);
    }

    public function test_the_create_screens_number_the_new_records(): void
    {
        $this->seedDemo();
        $this->loginAs(Role::Supervisor);

        Livewire::test(CreateVesselDischarge::class)
            ->fillForm(['vessel_id' => Vessel::where('active', true)->value('id'), 'commodity' => 'cpo', 'bl_quantity_kg' => 50000])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('VOY-2611-06', VesselDischarge::latest('id')->value('voyage_ref'));

        Livewire::test(CreateDeliveryOrder::class)
            ->fillForm(['customer_id' => Customer::where('active', true)->value('id'), 'commodity' => 'cpol', 'ordered_kg' => 10000])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('ORD-1005', DeliveryOrder::latest('id')->value('order_ref'));

        Livewire::test(CreateTank::class)
            ->fillForm(['name' => 'Crude Palm Oil 3', 'commodity' => 'cpo', 'capacity_kg' => 2_000_000])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('CPO-03', Tank::latest('id')->value('code'));

        Livewire::test(CreateBerth::class)
            ->fillForm(['name' => 'New berth'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertNotNull(Berth::latest('id')->value('code'));
    }

    public function test_a_typed_code_cannot_be_sent_from_the_screen(): void
    {
        $this->seedDemo();
        $this->loginAs(Role::Supervisor);

        Livewire::test(CreateDeliveryOrder::class)
            ->fillForm(['order_ref' => 'MY-OWN', 'customer_id' => Customer::where('active', true)->value('id'), 'commodity' => 'cpol', 'ordered_kg' => 10000])
            ->call('create');

        $this->assertNull(DeliveryOrder::where('order_ref', 'MY-OWN')->first());
    }
}
