<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Filament\Resources\BerthResource\Pages\ListBerths;
use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\DeliveryOrderResource\Pages\CreateDeliveryOrder;
use App\Filament\Resources\TruckResource\Pages\CreateTruck;
use App\Filament\Resources\TruckResource\Pages\ListTrucks;
use App\Filament\Resources\VesselDischargeResource\Pages\CreateVesselDischarge;
use App\Filament\Resources\VesselDischargeResource\Pages\RunDischarge;
use App\Filament\Resources\VesselResource\Pages\CreateVessel;
use App\Filament\Resources\VesselResource\Pages\EditVessel;
use App\Filament\Resources\VesselResource\Pages\ListVessels;
use App\Filament\Resources\VesselResource\Pages\ViewVessel;
use App\Models\AuditLog;
use App\Models\Berth;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Transporter;
use App\Models\Truck;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDischarge;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class RegistersUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-05 10:00'));
        $this->seed(DatabaseSeeder::class);
    }

    private function login(Role $role): User
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($u = User::where('role', $role->value)->firstOrFail());

        return $u;
    }

    public function test_registers_open_for_admins_and_supervisors_only(): void
    {
        $urls = ['/admin/vessels', '/admin/berths', '/admin/customers', '/admin/transporters', '/admin/trucks', '/admin/drivers'];
        $records = [
            '/admin/vessels/'.Vessel::first()->id, '/admin/berths/'.Berth::first()->id, '/admin/customers/'.Customer::first()->id,
            '/admin/trucks/'.Truck::first()->id, '/admin/drivers/'.Driver::first()->id,
            '/admin/transporters/'.Transporter::first()->id,
        ];

        foreach ([Role::Admin, Role::Supervisor] as $role) {
            $this->login($role);
            foreach ([...$urls, ...$records] as $url) {
                $this->get($url)->assertOk();
                $this->get($url.(preg_match('#/\d+$#', $url) ? '/edit' : '/create'))->assertOk();
            }
        }

        foreach ([Role::Operator, Role::Auditor] as $role) {
            $this->login($role);
            foreach ([...$urls, ...$records] as $url) {
                $this->get($url)->assertForbidden();                       // hidden from the menu AND refused by direct URL
            }
        }
    }

    public function test_only_supervisors_and_admins_can_open_the_create_screens(): void
    {
        foreach ([Role::Operator, Role::Auditor] as $role) {
            $this->login($role);
            foreach (['vessels', 'berths', 'customers', 'transporters', 'trucks', 'drivers'] as $r) {
                $this->get("/admin/{$r}/create")->assertForbidden();
            }
        }
    }

    // ---- forms ---------------------------------------------------------------------------------

    public function test_creating_a_vessel_validates_the_imo(): void
    {
        $this->login(Role::Supervisor);

        Livewire::test(CreateVessel::class)->fillForm(['name' => 'MV New', 'imo' => '12345'])->call('create')->assertHasFormErrors(['imo']);
        Livewire::test(CreateVessel::class)->fillForm(['name' => 'MV New', 'imo' => '9301122'])->call('create')->assertHasFormErrors(['imo']);   // duplicate of the seeded Pacific Dawn
        Livewire::test(CreateVessel::class)->fillForm(['name' => 'MV New', 'imo' => '9999991'])->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateVessel::class)->fillForm(['name' => 'MV No Imo'])->call('create')->assertHasNoFormErrors();     // IMO is optional

        $this->assertSame('9999991', Vessel::where('name', 'MV New')->value('imo'));
    }

    public function test_a_truck_plate_is_normalised_by_the_form_and_cannot_be_duplicated(): void
    {
        $this->login(Role::Supervisor);

        Livewire::test(CreateTruck::class)->fillForm(['plate' => 'kbz   123a', 'capacity_kg' => 30000])->call('create')->assertHasNoFormErrors();
        $this->assertTrue(Truck::where('plate', 'KBZ 123A')->exists());

        Livewire::test(CreateTruck::class)->fillForm(['plate' => 'KBZ 123A'])->call('create')->assertHasFormErrors(['plate']);
    }

    public function test_a_customer_name_must_be_unique(): void
    {
        $this->login(Role::Supervisor);

        Livewire::test(CreateCustomer::class)->fillForm(['name' => 'Bidco Africa'])->call('create')->assertHasFormErrors(['name']);
    }

    public function test_a_voyage_is_scheduled_by_picking_a_vessel_from_the_register(): void
    {
        $this->login(Role::Supervisor);
        $vessel = Vessel::where('name', 'MV Mombasa Pearl')->first();

        Livewire::test(CreateVesselDischarge::class)
            ->fillForm(['vessel_id' => $vessel->id, 'commodity' => 'cpol', 'bl_quantity_kg' => 900000])
            ->call('create')->assertHasNoFormErrors();

        $v = VesselDischarge::latest('id')->first();
        $this->assertStringStartsWith('VOY-', $v->voyage_ref);                  // numbered by the system
        $this->assertSame($vessel->id, $v->vessel_id);
        $this->assertSame('MV Mombasa Pearl', $v->vessel_name);                // filled from the register
        $this->assertSame(DischargeStatus::Scheduled, $v->status);
    }

    public function test_an_inactive_vessel_is_not_offered_for_scheduling(): void
    {
        $this->login(Role::Supervisor);
        $retired = Vessel::create(['name' => 'MV Retired', 'active' => false]);

        Livewire::test(CreateVesselDischarge::class)
            ->fillForm(['vessel_id' => $retired->id, 'commodity' => 'cpo', 'bl_quantity_kg' => 1000])
            ->call('create')->assertHasFormErrors(['vessel_id']);
    }

    public function test_an_order_is_created_for_a_customer_from_the_register(): void
    {
        $this->login(Role::Supervisor);
        $customer = Customer::where('name', 'Unga Group')->first();

        Livewire::test(CreateDeliveryOrder::class)
            ->fillForm(['customer_id' => $customer->id, 'commodity' => 'cpol', 'ordered_kg' => 50000])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame('Unga Group', DeliveryOrder::latest('id')->value('customer'));
    }

    public function test_editing_a_vessel_through_the_form_renames_its_voyages(): void
    {
        $this->login(Role::Supervisor);
        $vessel = Vessel::where('name', 'MV Borneo Trader')->first();

        Livewire::test(EditVessel::class, ['record' => $vessel->id])->fillForm(['name' => 'MV Borneo Voyager'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('MV Borneo Voyager', VesselDischarge::where('voyage_ref', 'VOY-2611-03')->value('vessel_name'));
    }

    // ---- tracking and compliance on screen ---------------------------------------------------------

    public function test_a_vessel_page_tells_you_where_the_ship_is(): void
    {
        $this->login(Role::Supervisor);

        Livewire::test(ViewVessel::class, ['record' => Vessel::where('name', 'MV Borneo Trader')->value('id')])
            ->assertSee('Discharging at berth B1')->assertSee('VOY-2611-03');
        Livewire::test(ViewVessel::class, ['record' => Vessel::where('name', 'MV Mombasa Pearl')->value('id')])
            ->assertSee('Not in port');
    }

    public function test_the_truck_list_shows_document_warnings(): void
    {
        $this->login(Role::Supervisor);

        Livewire::test(ListTrucks::class)
            ->assertSee('KDA 214X')->assertSee('Expired')                       // insurance expired
            ->assertSee('Expiring soon')                                        // KCB 887L inspection
            ->assertSee('Valid')->assertSee('Not recorded');
    }

    // ---- deactivate, never delete ------------------------------------------------------------------

    public function test_a_free_berth_can_be_switched_off_and_on(): void
    {
        $this->login(Role::Supervisor);
        $free = Berth::create(['code' => 'B9', 'name' => 'Spare']);

        Livewire::test(ListBerths::class)->callAction(TestAction::make('toggle_active')->table($free))->assertNotified('Deactivated');
        $this->assertFalse($free->fresh()->active);

        Livewire::test(ListBerths::class)->callAction(TestAction::make('toggle_active')->table($free->fresh()))->assertNotified('Activated');
        $this->assertTrue($free->fresh()->active);
    }

    public function test_things_in_use_cannot_be_switched_off(): void
    {
        $this->login(Role::Supervisor);
        $busyBerth = Berth::where('code', 'B1')->first();                       // Borneo Trader is discharging here
        $ship = Vessel::where('name', 'MV Borneo Trader')->first();

        Livewire::test(ListBerths::class)->callAction(TestAction::make('toggle_active')->table($busyBerth))->assertNotified('Cannot deactivate');
        Livewire::test(ListVessels::class)->callAction(TestAction::make('toggle_active')->table($ship))->assertNotified('Cannot deactivate');

        $this->assertTrue($busyBerth->fresh()->active);
        $this->assertTrue($ship->fresh()->active);
    }

    public function test_operators_and_auditors_do_not_get_the_register_screens_at_all(): void
    {
        $this->login(Role::Operator);
        Livewire::test(ListVessels::class)->assertForbidden();

        $this->login(Role::Auditor);
        Livewire::test(ListTrucks::class)->assertForbidden();
    }

    // ---- the berth picker on the voyage page -----------------------------------------------------------

    public function test_marking_a_vessel_berthed_asks_for_a_free_berth(): void
    {
        $this->login(Role::Operator);
        $voyage = VesselDischarge::where('voyage_ref', 'VOY-2611-05')->first();         // scheduled
        $occupied = Berth::where('code', 'B1')->first();
        $free = Berth::create(['code' => 'B3', 'name' => 'Berth 3']);

        // The occupied berth is not offered, so choosing it fails validation.
        Livewire::test(RunDischarge::class, ['record' => $voyage->id])
            ->set('berthData.berth_id', $occupied->id)->call('berth')->assertHasErrors(['berthData.berth_id']);
        $this->assertSame(DischargeStatus::Scheduled, $voyage->fresh()->status);

        Livewire::test(RunDischarge::class, ['record' => $voyage->id])
            ->set('berthData.berth_id', $free->id)->call('berth')->assertNotified('Ship berthed');

        $this->assertSame($free->id, $voyage->fresh()->berth_id);
        $this->assertSame(DischargeStatus::Berthed, $voyage->fresh()->status);
    }

    public function test_seed_is_idempotent_with_registers(): void
    {
        $counts = fn () => [Vessel::count(), Customer::count(), Truck::count(), Driver::count(), Berth::count(), AuditLog::count()];
        $before = $counts();

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, $counts());                                        // including the audit log: no noise
        $this->assertSame(0, VesselDischarge::whereNull('vessel_id')->count());
        $this->assertSame(0, DeliveryOrder::whereNull('customer_id')->count());
    }
}
