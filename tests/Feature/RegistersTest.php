<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Fleet\Enums\DocumentStatus;
use App\Domain\Fleet\Exceptions\BerthOccupiedException;
use App\Domain\Fleet\Exceptions\BerthRequiredException;
use App\Domain\Fleet\Exceptions\RegisterInactiveException;
use App\Domain\Fleet\Exceptions\VesselBusyException;
use App\Domain\Fleet\Services\ComplianceChecker;
use App\Domain\Fleet\Services\RegisterBackfill;
use App\Domain\Operations\Actions\AdvanceDischargeStatus;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Models\Berth;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Transporter;
use App\Models\Truck;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDischarge;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegistersTest extends TestCase
{
    use RefreshDatabase;

    private function voyage(string $ref, ?Vessel $v = null, DischargeStatus $s = DischargeStatus::Scheduled): VesselDischarge
    {
        return VesselDischarge::create([
            'voyage_ref' => $ref, 'vessel_id' => $v?->id, 'vessel_name' => $v?->name ?? 'MV Typed Name',
            'commodity' => Commodity::CPO, 'bl_quantity_kg' => 1_000_000, 'status' => $s,
        ]);
    }

    private function advance(VesselDischarge $d, DischargeStatus $to, ?Berth $b = null): VesselDischarge
    {
        return app(AdvanceDischargeStatus::class)($d, $to, $b);
    }

    // ---- compliance: documents warn, never block ---------------------------------------

    public function test_document_statuses_around_the_expiry_date(): void
    {
        $today = Carbon::parse('2026-11-10');

        $this->assertSame(DocumentStatus::Expired, ComplianceChecker::statusOf(Carbon::parse('2026-11-09'), $today));
        $this->assertSame(DocumentStatus::ExpiringSoon, ComplianceChecker::statusOf(Carbon::parse('2026-11-10'), $today));   // today counts as soon
        $this->assertSame(DocumentStatus::ExpiringSoon, ComplianceChecker::statusOf(Carbon::parse('2026-12-10'), $today));   // day 30
        $this->assertSame(DocumentStatus::Valid, ComplianceChecker::statusOf(Carbon::parse('2026-12-11'), $today));          // day 31
        $this->assertSame(DocumentStatus::Missing, ComplianceChecker::statusOf(null, $today));
    }

    public function test_warnings_are_plain_sentences(): void
    {
        $truck = new Truck(['plate' => 'KDA 214X', 'insurance_expiry' => '2026-11-07', 'inspection_expiry' => '2026-11-20']);
        $driver = new Driver(['name' => 'Joseph Kamau', 'licence_expiry' => null]);

        $w = (new ComplianceChecker)->warningsFor($truck, $driver, Carbon::parse('2026-11-10'));

        $this->assertSame([
            'Truck KDA 214X insurance expired 3 days ago (2026-11-07)',
            'Truck KDA 214X inspection expires in 10 days (2026-11-20)',
            'Driver Joseph Kamau licence expiry date is not recorded',
        ], $w);
        $this->assertSame([], (new ComplianceChecker)->warningsFor(new Truck(['plate' => 'KAA 1A', 'insurance_expiry' => '2027-06-01', 'inspection_expiry' => '2027-06-01']), null, Carbon::parse('2026-11-10')));
    }

    public function test_worst_status_summarises_documents(): void
    {
        $on = Carbon::parse('2026-11-10');

        $this->assertSame(DocumentStatus::Expired, ComplianceChecker::worst([Carbon::parse('2027-01-01'), Carbon::parse('2026-01-01')], $on));
        $this->assertSame(DocumentStatus::ExpiringSoon, ComplianceChecker::worst([Carbon::parse('2027-01-01'), Carbon::parse('2026-11-20')], $on));
        $this->assertSame(DocumentStatus::Valid, ComplianceChecker::worst([Carbon::parse('2027-01-01')], $on));
    }

    // ---- registers keep their data tidy -------------------------------------------------

    public function test_a_plate_is_normalised_and_unique_whatever_the_typing(): void
    {
        Truck::create(['plate' => 'kda   214x']);

        $this->assertSame('KDA 214X', Truck::first()->plate);

        $this->expectException(QueryException::class);
        Truck::create(['plate' => ' KDA 214X ']);                 // same truck typed differently
    }

    public function test_renaming_a_vessel_or_customer_updates_the_records_that_show_the_name(): void
    {
        $vessel = Vessel::create(['name' => 'MV Pacific Dawn']);
        $voyage = $this->voyage('V1', $vessel);
        $customer = Customer::create(['name' => 'Bidco Africa']);
        $order = DeliveryOrder::create(['order_ref' => 'O1', 'customer_id' => $customer->id, 'commodity' => Commodity::CPO, 'ordered_kg' => 1000]);

        $this->assertSame('MV Pacific Dawn', $voyage->vessel_name);
        $this->assertSame('Bidco Africa', $order->customer);

        $vessel->update(['name' => 'MV Pacific Sunrise']);
        $customer->update(['name' => 'Bidco Africa Ltd']);

        $this->assertSame('MV Pacific Sunrise', $voyage->fresh()->vessel_name);
        $this->assertSame('Bidco Africa Ltd', $order->fresh()->customer);
    }

    public function test_choosing_a_vessel_fills_the_name_from_the_register(): void
    {
        $a = Vessel::create(['name' => 'MV Alpha']);
        $b = Vessel::create(['name' => 'MV Bravo']);

        $v = VesselDischarge::create(['voyage_ref' => 'V9', 'vessel_id' => $a->id, 'commodity' => Commodity::CPO, 'bl_quantity_kg' => 5]);
        $this->assertSame('MV Alpha', $v->vessel_name);

        $v->update(['vessel_id' => $b->id]);
        $this->assertSame('MV Bravo', $v->fresh()->vessel_name);
    }

    public function test_imo_numbers_are_unique_but_optional(): void
    {
        Vessel::create(['name' => 'A', 'imo' => null]);
        Vessel::create(['name' => 'B', 'imo' => null]);          // several vessels may lack one
        Vessel::create(['name' => 'C', 'imo' => '9074729']);

        $this->expectException(QueryException::class);
        Vessel::create(['name' => 'D', 'imo' => '9074729']);
    }

    // ---- the backfill that upgrades live data -------------------------------------------

    public function test_backfill_builds_registers_from_typed_names_and_links_everything(): void
    {
        $this->voyage('L1')->update(['vessel_name' => 'MV  Legacy  Star']);                  // messy spacing
        $this->voyage('L2')->update(['vessel_name' => 'MV Legacy Star']);
        $this->voyage('L3')->update(['vessel_name' => 'MV Other']);
        DeliveryOrder::create(['order_ref' => 'LO1', 'customer' => 'Unga Group', 'commodity' => Commodity::CPO, 'ordered_kg' => 1]);
        DeliveryOrder::create(['order_ref' => 'LO2', 'customer' => 'Unga Group', 'commodity' => Commodity::CPO, 'ordered_kg' => 1]);

        $created = RegisterBackfill::run();

        $this->assertSame(['vessels' => 2, 'customers' => 1], $created);
        $this->assertSame(['MV Legacy Star', 'MV Other'], Vessel::orderBy('name')->pluck('name')->all());
        $this->assertSame(
            VesselDischarge::where('voyage_ref', 'L1')->value('vessel_id'),
            VesselDischarge::where('voyage_ref', 'L2')->value('vessel_id'),
            'the same ship typed two ways becomes one register entry',
        );
        $this->assertSame(0, VesselDischarge::whereNull('vessel_id')->count());
        $this->assertSame(0, DeliveryOrder::whereNull('customer_id')->count());

        $this->assertSame(['vessels' => 0, 'customers' => 0], RegisterBackfill::run(), 'running it again changes nothing');
    }

    // ---- berthing rules ------------------------------------------------------------------

    public function test_a_berth_is_required_to_mark_a_vessel_berthed(): void
    {
        $this->expectException(BerthRequiredException::class);
        $this->advance($this->voyage('V1', Vessel::create(['name' => 'A'])), DischargeStatus::Berthed);
    }

    public function test_one_vessel_per_berth(): void
    {
        $berth = Berth::create(['code' => 'B1', 'name' => 'Berth 1']);
        $first = $this->voyage('V1', Vessel::create(['name' => 'MV First']));
        $second = $this->voyage('V2', Vessel::create(['name' => 'MV Second']));

        $this->advance($first, DischargeStatus::Berthed, $berth);
        $this->assertSame($berth->id, $first->fresh()->berth_id);
        $this->assertSame($first->id, $berth->occupant()->id);

        try {
            $this->advance($second, DischargeStatus::Berthed, $berth);
            $this->fail('berth should be occupied');
        } catch (BerthOccupiedException $e) {
            $this->assertStringContainsString('MV First', $e->getMessage());
        }
        $this->assertSame(DischargeStatus::Scheduled, $second->fresh()->status);

        // Still occupied while discharging, free once the first ship is cancelled off the berth.
        $this->advance($first->fresh(), DischargeStatus::Discharging);
        $this->assertSame($first->id, $berth->occupant()->id);
    }

    public function test_a_berth_frees_up_when_the_voyage_is_cancelled(): void
    {
        $berth = Berth::create(['code' => 'B1', 'name' => 'Berth 1']);
        $first = $this->voyage('V1', Vessel::create(['name' => 'MV First']));
        $second = $this->voyage('V2', Vessel::create(['name' => 'MV Second']));

        $this->advance($first, DischargeStatus::Berthed, $berth);
        $this->advance($first->fresh(), DischargeStatus::Cancelled);

        $this->assertNull($berth->occupant());
        $this->advance($second, DischargeStatus::Berthed, $berth);
        $this->assertSame($second->id, $berth->occupant()->id);
    }

    public function test_a_vessel_cannot_be_alongside_on_two_voyages(): void
    {
        $ship = Vessel::create(['name' => 'MV Twice']);
        $one = $this->voyage('V1', $ship);
        $two = $this->voyage('V2', $ship);
        $this->advance($one, DischargeStatus::Berthed, Berth::create(['code' => 'B1', 'name' => 'Berth 1']));

        $this->expectException(VesselBusyException::class);
        $this->advance($two, DischargeStatus::Berthed, Berth::create(['code' => 'B2', 'name' => 'Berth 2']));
    }

    public function test_inactive_berths_and_vessels_cannot_be_used(): void
    {
        $closed = Berth::create(['code' => 'B9', 'name' => 'Under repair', 'active' => false]);
        try {
            $this->advance($this->voyage('V1', Vessel::create(['name' => 'A'])), DischargeStatus::Berthed, $closed);
            $this->fail('closed berth accepted');
        } catch (RegisterInactiveException $e) {
            $this->assertStringContainsString('B9', $e->getMessage());
        }

        $retired = Vessel::create(['name' => 'MV Retired', 'active' => false]);
        $this->expectException(RegisterInactiveException::class);
        $this->advance($this->voyage('V2', $retired), DischargeStatus::Berthed, Berth::create(['code' => 'B1', 'name' => 'Berth 1']));
    }

    // ---- who may change registers ----------------------------------------------------------

    public function test_permissions_on_registers(): void
    {
        $user = fn (Role $r) => User::factory()->role($r)->create();

        foreach ([Vessel::class, Berth::class, Customer::class, Truck::class, Driver::class, Transporter::class] as $model) {
            $this->assertTrue($user(Role::Supervisor)->can('create', $model), "supervisor create {$model}");
            $this->assertTrue($user(Role::Admin)->can('update', new $model), "admin update {$model}");
            $this->assertFalse($user(Role::Operator)->can('create', $model), "operator create {$model}");
            $this->assertFalse($user(Role::Auditor)->can('update', new $model), "auditor update {$model}");
            $this->assertTrue($user(Role::Supervisor)->can('viewAny', $model), "supervisor view {$model}");
            $this->assertFalse($user(Role::Auditor)->can('viewAny', $model), "auditor view {$model}");      // registers are for the people who maintain them
            $this->assertFalse($user(Role::Operator)->can('viewAny', $model), "operator view {$model}");

            foreach (Role::cases() as $r) {
                $this->assertFalse($user($r)->can('delete', new $model), "{$r->value} delete {$model}");
            }
        }
    }

    public function test_the_database_refuses_two_ships_on_one_berth_or_one_ship_on_two_berths(): void
    {
        $berth = Berth::create(['code' => 'B1', 'name' => 'Berth 1']);
        $ship = Vessel::create(['name' => 'MV Solo']);
        $a = $this->voyage('VA', $ship);
        $a->update(['berth_id' => $berth->id, 'status' => DischargeStatus::Discharging]);

        // Written straight to the model, bypassing every check in the action: the unique guard columns still refuse.
        try {
            $b = $this->voyage('VB', Vessel::create(['name' => 'MV Other']));
            $b->update(['berth_id' => $berth->id, 'status' => DischargeStatus::Berthed]);
            $this->fail('two ships accepted on one berth');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        try {
            $c = $this->voyage('VC', $ship);
            $c->update(['berth_id' => Berth::create(['code' => 'B2', 'name' => 'Berth 2'])->id, 'status' => DischargeStatus::Berthed]);
            $this->fail('one ship accepted on two berths');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_the_guard_columns_follow_the_voyage_status(): void
    {
        $berth = Berth::create(['code' => 'B1', 'name' => 'Berth 1']);
        $ship = Vessel::create(['name' => 'MV Solo']);
        $v = $this->voyage('VA', $ship);

        $this->assertNull($v->active_berth_id);                                              // scheduled: not alongside

        $v = $this->advance($v, DischargeStatus::Berthed, $berth);
        $this->assertSame($berth->id, $v->active_berth_id);
        $this->assertSame($ship->id, $v->active_vessel_id);

        $v = $this->advance($v, DischargeStatus::Cancelled);
        $this->assertNull($v->active_berth_id);                                              // the berth and the ship are free again
        $this->assertNull($v->active_vessel_id);
        $this->assertSame($berth->id, $v->berth_id);                                         // history keeps where it was
    }

    public function test_the_migration_marks_ships_already_alongside(): void
    {
        $berth = Berth::create(['code' => 'B1', 'name' => 'Berth 1']);
        $ship = Vessel::create(['name' => 'MV Old']);
        $v = $this->voyage('VOLD', $ship);
        $v->update(['berth_id' => $berth->id, 'status' => DischargeStatus::Discharging]);
        DB::table('vessel_discharges')->update(['active_berth_id' => null, 'active_vessel_id' => null]);   // as an old row would be

        $this->assertNull($v->fresh()->active_berth_id);
        $v->fresh()->save();                                                                  // any normal save repairs it
        $this->assertSame($berth->id, $v->fresh()->active_berth_id);
    }
}
