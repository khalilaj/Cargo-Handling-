<?php

namespace Tests\Feature;

use App\Domain\Access\RegisterUsage;
use App\Domain\Access\Role;
use App\Domain\Cargo\Actions\RecordVesselDischarge;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Cargo\Exceptions\TankArchivedException;
use App\Domain\Operations\Services\OperationalAlerts;
use App\Domain\Reporting\StockPositionReport;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\TankResource\Pages\ListTanks;
use App\Filament\Resources\TransporterResource\Pages\ListTransporters;
use App\Filament\Resources\TruckResource\Pages\ListTrucks;
use App\Filament\Widgets\StockOverview;
use App\Filament\Widgets\TankGauges;
use App\Models\AuditLog;
use App\Models\Berth;
use App\Models\CargoMovement;
use App\Models\CorrectionRequest;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Tank;
use App\Models\Transporter;
use App\Models\Truck;
use App\Models\TruckVisit;
use App\Models\Vessel;
use App\Models\VesselDischarge;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/** Nobody deletes an active record. An admin archives it, and only then may delete it, if nothing uses it. */
class ArchiveThenDeleteTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWithEdgeStates();
    }

    private function row(string $name, $record): TestAction
    {
        return TestAction::make($name)->table($record);
    }

    /** Press a button the way a hand-made request would. Any refusal is fine; the caller checks the data. */
    private function crafted(string $page, string $action, $record): void
    {
        try {
            Livewire::test($page)->call('mountTableAction', $action, $record->getKey())->call('callMountedTableAction');
        } catch (\Throwable) {
            // refused
        }
    }

    private function emptyTank(bool $active): Tank
    {
        return Tank::create(['name' => 'Spare', 'commodity' => 'cpo', 'capacity_kg' => 1000, 'active' => $active]);
    }

    // ---- policies, all seven registers ----------------------------------------------------------------------------

    public function test_policy_rules_for_every_register(): void
    {
        $models = [Tank::class, Vessel::class, Berth::class, Customer::class, Transporter::class, Truck::class, Driver::class];

        foreach ($models as $model) {
            $active = $model::where('active', true)->firstOrFail();
            $archived = $model::where('active', true)->firstOrFail()->replicate();
            $archived->active = false;
            $archived->exists = true;                                   // stands in for a saved, archived record

            foreach ($this->roles() as $role) {
                $user = $this->loginAs($role);
                $admin = $role === Role::Admin;
                $name = class_basename($model)." as {$role->value}";

                $this->assertFalse($user->can('delete', $active), "{$name}: delete of an ACTIVE record");
                $this->assertFalse($user->can('deleteAny', $model), "{$name}: bulk delete");
                $this->assertSame($admin, $user->can('delete', $archived), "{$name}: delete of an archived record");
                $this->assertSame($admin, $user->can('archive', $active), "{$name}: archive");
                $this->assertFalse($user->can('archive', $archived), "{$name}: archive of an archived record");
                $this->assertSame($admin, $user->can('restore', $archived), "{$name}: restore");
                $this->assertFalse($user->can('restore', $active), "{$name}: restore of an active record");
            }
        }
    }

    // ---- tanks -------------------------------------------------------------------------------------------------------

    public function test_tank_buttons_by_role_and_state(): void
    {
        $live = $this->emptyTank(true);
        $archived = $this->emptyTank(false);

        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            $admin = $role === Role::Admin;
            $page = Livewire::test(ListTanks::class)->removeTableFilter('active');

            $page->assertActionHidden($this->row('delete', $live));
            $admin ? $page->assertActionVisible($this->row('archive', $live)) : $page->assertActionHidden($this->row('archive', $live));
            $page->assertActionHidden($this->row('restore', $live));
            $admin ? $page->assertActionVisible($this->row('delete', $archived)) : $page->assertActionHidden($this->row('delete', $archived));
            $admin ? $page->assertActionVisible($this->row('restore', $archived)) : $page->assertActionHidden($this->row('restore', $archived));
            $page->assertActionHidden($this->row('archive', $archived));
        }
    }

    public function test_admin_archives_restores_and_deletes_an_unused_tank(): void
    {
        $tank = $this->emptyTank(true);
        $this->loginAs(Role::Admin);

        Livewire::test(ListTanks::class)->callAction($this->row('archive', $tank))->assertNotified('Archived');
        $this->assertFalse($tank->fresh()->active);

        Livewire::test(ListTanks::class)->removeTableFilter('active')->callAction($this->row('restore', $tank->fresh()))->assertNotified('Restored');
        $this->assertTrue($tank->fresh()->active);

        $tank->update(['active' => false]);
        Livewire::test(ListTanks::class)->removeTableFilter('active')->callAction($this->row('delete', $tank->fresh()))->assertNotified('Deleted');
        $this->assertNull(Tank::find($tank->id));
    }

    public function test_archived_tank_is_hidden_by_default_and_found_through_the_filter(): void
    {
        $tank = $this->emptyTank(false);
        $this->loginAs(Role::Admin);

        Livewire::test(ListTanks::class)->assertCanNotSeeTableRecords([$tank]);
        Livewire::test(ListTanks::class)->removeTableFilter('active')->assertCanSeeTableRecords([$tank]);
        Livewire::test(ListTanks::class)->filterTable('active', false)->assertCanSeeTableRecords([$tank]);
    }

    public function test_a_tank_with_ledger_movements_cannot_be_deleted_and_says_why(): void
    {
        $tank = Tank::where('code', 'CPOL-01')->firstOrFail();
        $count = $tank->movements()->count();
        $this->assertGreaterThan(0, $count);
        $tank->update(['active' => false]);

        $this->loginAs(Role::Admin);
        Livewire::test(ListTanks::class)->removeTableFilter('active')
            ->callAction($this->row('delete', $tank->fresh()))
            ->assertNotified('Not done');

        $this->assertNotNull(Tank::find($tank->id));
        $this->assertSame($count, $tank->movements()->count());
        $this->assertSame(["Tank CPOL-01 has {$count} ledger movements."], RegisterUsage::blockers($tank));
    }

    public function test_the_database_itself_refuses_to_delete_a_tank_that_has_ledger_rows(): void
    {
        $tank = Tank::whereHas('movements')->firstOrFail();
        $rows = $tank->movements()->count();

        try {
            // A savepoint, as in the app's own delete: PostgreSQL aborts the whole transaction after a refused statement.
            DB::transaction(fn () => $tank->delete());
            $this->fail('The tank was deleted although it has ledger rows.');
        } catch (QueryException) {
            // expected: the foreign key restricts the delete
        }

        $this->assertSame($rows, CargoMovement::where('tank_id', $tank->id)->count());
    }

    public function test_an_archived_tank_takes_no_new_movements(): void
    {
        $tank = Tank::create(['name' => 'Spare', 'commodity' => Commodity::CPO, 'capacity_kg' => 1_000_000, 'active' => false]);

        $this->expectException(TankArchivedException::class);
        try {
            app(RecordVesselDischarge::class)($tank->id, 10_000, now(), 'arch-1', 'MV Test');
        } finally {
            $this->assertSame(0, CargoMovement::where('tank_id', $tank->id)->count());
            $this->assertSame(0, $tank->fresh()->current_volume_kg);
        }
    }

    public function test_archived_tanks_leave_the_boards_but_the_ledger_report_still_reconciles(): void
    {
        $capacity = fn () => (int) Tank::where('commodity', 'cpo')->where('active', true)->sum('capacity_kg');
        $spare = Tank::create(['name' => 'Spare', 'commodity' => Commodity::CPO, 'capacity_kg' => 1_000_000, 'active' => false]);
        $spare->forceFill(['current_volume_kg' => 990_000])->save();      // nearly full: it would raise an alert if it were counted
        $this->loginAs(Role::Admin);

        Livewire::test(TankGauges::class)->assertDontSee($spare->code);
        Livewire::test(StockOverview::class)->assertSee('of '.number_format($capacity() / 1000, 1).' t capacity');

        $archivedAlerts = app(OperationalAlerts::class)->forRole(Role::Admin, 100)->pluck('target.id')->all();
        $this->assertNotContains($spare->id, $archivedAlerts);

        $report = app(StockPositionReport::class)(now()->subYear(), now());
        $this->assertTrue(collect($report['rows'])->every(fn ($r) => $r['reconciles']));
    }

    public function test_a_tank_holding_cargo_cannot_be_archived(): void
    {
        $tank = Tank::where('current_volume_kg', '>', 0)->firstOrFail();
        $this->loginAs(Role::Admin);

        Livewire::test(ListTanks::class)->callAction($this->row('archive', $tank))->assertNotified('Not done');
        $this->assertTrue($tank->fresh()->active);
    }

    public function test_crafted_requests_cannot_archive_restore_or_delete(): void
    {
        $live = $this->emptyTank(true);
        $archived = $this->emptyTank(false);

        // an admin may not delete an active record, whatever the request says
        $this->loginAs(Role::Admin);
        $this->crafted(ListTanks::class, 'delete', $live);
        $this->assertNotNull(Tank::find($live->id));

        foreach ([Role::Supervisor, Role::Operator, Role::Auditor] as $role) {
            $this->loginAs($role);
            $this->crafted(ListTanks::class, 'delete', $archived);
            $this->crafted(ListTanks::class, 'archive', $live);
            $this->crafted(ListTanks::class, 'restore', $archived);

            $this->assertNotNull(Tank::find($archived->id), "{$role->value} deleted an archived tank");
            $this->assertTrue($live->fresh()->active, "{$role->value} archived a tank");
            $this->assertFalse($archived->fresh()->active, "{$role->value} restored a tank");
        }
    }

    public function test_the_delete_button_cannot_even_be_mounted_without_the_right(): void
    {
        $archived = $this->emptyTank(false);
        $this->loginAs(Role::Supervisor);

        $page = Livewire::test(ListTanks::class)->removeTableFilter('active')->call('mountTableAction', 'delete', $archived->id);
        $this->assertEmpty($page->get('mountedActions'), 'the delete confirmation must not open for a supervisor');
        $page->call('callMountedTableAction');
        $this->assertNotNull(Tank::find($archived->id));
    }

    public function test_tank_table_offers_no_bulk_actions(): void
    {
        foreach ([Role::Admin, Role::Supervisor] as $role) {
            $this->loginAs($role);
            $table = Livewire::test(ListTanks::class)->instance()->getTable();
            $this->assertSame([], $table->getBulkActions());
        }
    }

    // ---- trucks ------------------------------------------------------------------------------------------------------

    public function test_a_truck_with_visits_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $used = Truck::whereHas('visits')->firstOrFail();
        $used->update(['active' => false]);
        $visits = $used->visits()->count();
        $unused = Truck::create(['plate' => 'KZZ 001Z', 'capacity_kg' => 30_000, 'tare_kg' => 14_000, 'active' => false]);

        $this->loginAs(Role::Admin);
        $page = fn () => Livewire::test(ListTrucks::class)->removeTableFilter('active');

        $page()->callAction($this->row('delete', $used->fresh()))->assertNotified('Not done');
        $this->assertNotNull(Truck::find($used->id));
        $this->assertSame(["Truck {$used->plate} has {$visits} truck visit".($visits === 1 ? '' : 's').'.'], RegisterUsage::blockers($used));

        $page()->callAction($this->row('delete', $unused))->assertNotified('Deleted');
        $this->assertNull(Truck::find($unused->id));
    }

    public function test_only_an_admin_archives_a_truck(): void
    {
        $truck = Truck::whereDoesntHave('activeVisit')->where('active', true)->firstOrFail();

        $this->loginAs(Role::Supervisor);
        Livewire::test(ListTrucks::class)->assertActionHidden($this->row('archive', $truck));
        $this->crafted(ListTrucks::class, 'archive', $truck);
        $this->assertTrue($truck->fresh()->active);

        $this->loginAs(Role::Admin);
        Livewire::test(ListTrucks::class)->callAction($this->row('archive', $truck))->assertNotified('Archived');
        $this->assertFalse($truck->fresh()->active);
    }

    // ---- customers and transporters -------------------------------------------------------------------------------------

    public function test_a_customer_with_orders_cannot_be_deleted(): void
    {
        $customer = Customer::whereHas('orders')->firstOrFail();
        $customer->update(['active' => false]);
        $orders = $customer->orders()->count();

        $this->loginAs(Role::Admin);
        Livewire::test(ListCustomers::class)->removeTableFilter('active')
            ->callAction($this->row('delete', $customer->fresh()))->assertNotified('Not done');

        $this->assertNotNull(Customer::find($customer->id));
        $this->assertStringContainsString("has {$orders} delivery order", RegisterUsage::blockers($customer)[0]);

        $free = Customer::create(['name' => 'Nobody Ltd', 'active' => false]);
        Livewire::test(ListCustomers::class)->removeTableFilter('active')->callAction($this->row('delete', $free))->assertNotified('Deleted');
        $this->assertNull(Customer::find($free->id));
    }

    public function test_a_transporter_with_trucks_cannot_be_deleted(): void
    {
        $transporter = Transporter::whereHas('trucks')->firstOrFail();
        $transporter->update(['active' => false]);

        $this->loginAs(Role::Admin);
        Livewire::test(ListTransporters::class)->removeTableFilter('active')
            ->callAction($this->row('delete', $transporter->fresh()))->assertNotified('Not done');

        $this->assertNotNull(Transporter::find($transporter->id));
        $this->assertStringContainsString('truck', implode(' ', RegisterUsage::blockers($transporter)));
    }

    // ---- the ledger and history are never deletable ------------------------------------------------------------------------

    public function test_history_cannot_be_deleted_by_anyone(): void
    {
        $models = [CargoMovement::class, VesselDischarge::class, DeliveryOrder::class, TruckVisit::class, CorrectionRequest::class, AuditLog::class];

        foreach ($models as $model) {
            foreach ($this->roles() as $role) {
                $user = $this->loginAs($role);
                $this->assertFalse($user->can('delete', $model::first()), class_basename($model).' delete for '.$role->value);
                $this->assertFalse($user->can('deleteAny', $model), class_basename($model).' bulk delete for '.$role->value);
            }
        }
    }
}
