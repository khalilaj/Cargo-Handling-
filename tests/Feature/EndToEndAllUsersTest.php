<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Cargo\Actions\RecordVesselDischarge;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Cargo\Exceptions\TankArchivedException;
use App\Domain\Corrections\Actions\ApproveCorrection;
use App\Domain\Corrections\Enums\CorrectionStatus;
use App\Domain\Corrections\Exceptions\CorrectionAlreadyDecidedException;
use App\Domain\Corrections\Exceptions\NotAuthorizedException;
use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Domain\Reporting\StockPositionReport;
use App\Filament\Resources\CargoMovementResource\Pages\ListCargoMovements;
use App\Filament\Resources\CorrectionRequestResource\Pages\ListCorrectionRequests;
use App\Filament\Resources\DeliveryOrderResource\Pages\CreateDeliveryOrder;
use App\Filament\Resources\DeliveryOrderResource\Pages\RunDelivery;
use App\Filament\Resources\TankResource\Pages\CreateTank;
use App\Filament\Resources\TankResource\Pages\ListTanks;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\VesselDischargeResource\Pages\CreateVesselDischarge;
use App\Filament\Resources\VesselDischargeResource\Pages\ListVesselDischarges;
use App\Filament\Resources\VesselDischargeResource\Pages\RunDischarge;
use App\Filament\Resources\VesselResource\Pages\ListVessels;
use App\Filament\Widgets\InventoryTrend;
use App\Filament\Widgets\RecentMovements;
use App\Filament\Widgets\StockOverview;
use App\Filament\Widgets\TankGauges;
use App\Filament\Widgets\TrucksOnSite;
use App\Filament\Widgets\VesselsInPort;
use App\Models\AuditLog;
use App\Models\Berth;
use App\Models\CargoMovement;
use App\Models\CorrectionRequest;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\TruckVisit;
use App\Models\User;
use App\Models\VesselDischarge;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\RoleScenario;
use Tests\TestCase;
use Throwable;

/**
 * The full rehearsal of the live demo, one test per act, in the order the demo is presented.
 * The yard is the live demo yard (not the test seed). Everything is done the way a person does it:
 * opening screens, pressing wizard buttons, using the quick-amount chips and the list-page actions.
 * Numbers in the test names and in the failure messages say which act and step broke.
 */
class EndToEndAllUsersTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    private const ALL = ['admin', 'supervisor', 'operator', 'auditor'];

    private const MANAGERS = ['admin', 'supervisor'];

    private const OVERSEERS = ['admin', 'supervisor', 'auditor'];

    private const ADMIN = ['admin'];

    /** The highest audit log id before the run starts (the seeding itself is not part of the run). */
    private int $auditFloor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['demo.extra' => true]);
        $this->seed(DatabaseSeeder::class);
        $this->auditFloor = (int) AuditLog::max('id');
    }

    // ---- small helpers ------------------------------------------------------------------------------------------

    private function voyage(string $ref): VesselDischarge
    {
        return VesselDischarge::where('voyage_ref', $ref)->firstOrFail();
    }

    private function order(string $ref): DeliveryOrder
    {
        return DeliveryOrder::where('order_ref', $ref)->firstOrFail();
    }

    private function tank(string $code): Tank
    {
        return Tank::where('code', $code)->firstOrFail();
    }

    private function visitOf(DeliveryOrder $order, string $plate): TruckVisit
    {
        return TruckVisit::where('delivery_order_id', $order->id)
            ->whereHas('truck', fn ($q) => $q->where('plate', $plate))->latest('id')->firstOrFail();
    }

    private function row(string $name, $record): TestAction
    {
        return TestAction::make($name)->table($record);
    }

    private function level(string $code): int
    {
        return (int) Tank::where('code', $code)->value('current_volume_kg');
    }

    private function wizard(string $page, int $id): Testable
    {
        return Livewire::test($page, ['record' => $id]);
    }

    /** Checksum of every tank's volume, so "nothing changed" is one comparison. */
    private function tankChecksum(): string
    {
        return md5(json_encode(Tank::orderBy('id')->pluck('current_volume_kg', 'id')->all()));
    }

    /** Everything a forbidden button could change. */
    private function snapshot(): array
    {
        return [
            'ledger' => CargoMovement::count(),
            'tanks' => $this->tankChecksum(),
            'tank_count' => Tank::count(),
            'tank_active' => Tank::orderBy('id')->pluck('active', 'id')->all(),
            'voyages' => VesselDischarge::orderBy('id')->get()->map(fn ($v) => [$v->id, $v->status->value, $v->berth_id])->all(),
            'orders' => DeliveryOrder::orderBy('id')->get()->map(fn ($o) => [$o->id, $o->status->value])->all(),
            'visits' => TruckVisit::orderBy('id')->get()->map(fn ($v) => [$v->id, $v->status->value])->all(),
            'corrections' => CorrectionRequest::orderBy('id')->get()->map(fn ($c) => [$c->id, $c->status->value])->all(),
            'users' => User::count(),
        ];
    }

    /** Run something a role must not be able to do. It may be refused in any way, but nothing may change. */
    private function assertRefusedAndNothingChanged(callable $attempt, string $what): void
    {
        $before = $this->snapshot();
        try {
            $attempt();
        } catch (Throwable) {
            // refused, which is the point
        }
        $this->assertSame($before, $this->snapshot(), "{$what}: something changed");
    }

    /** Take one truck from where it stands to the gate, the way an operator does: chips for weights and quantity. */
    private function advanceTruck(Testable $w, TruckVisit $visit, Tank $tank, string $tag): void
    {
        $status = fn () => $visit->fresh()->status;
        $w->call('selectVisit', $visit->id);

        if ($status() === VisitStatus::Arrived) {
            $tare = (int) $visit->truck->tare_kg;
            $w->assertSee('Weigh the empty truck')->assertSee('Usual empty weight')
                ->call('setWeight', 'in', $tare)->assertSet('weighInData.kg', $tare)->call('weighIn')->assertNotified('Weighed in');
            $this->assertSame(VisitStatus::WeighedIn, $status(), "{$tag}: weigh in");
        }

        if ($status() === VisitStatus::WeighedIn) {
            $w->assertSee('Move the truck into the loading bay')->call('startTruckLoading')->assertNotified('Loading started');
            $this->assertSame(VisitStatus::Loading, $status(), "{$tag}: start loading");
        }

        if ($status() === VisitStatus::Loading) {
            $w->set('loadData.tank_id', $tank->id);
            $chips = $w->instance()->quantityChips();
            $kg = end($chips)['kg'];
            $this->assertGreaterThan(0, $kg, "{$tag}: a quantity chip is offered");
            $w->call('setQuantity', $kg)->assertSet('loadData.quantity_kg', $kg)->call('load')->assertNotified('Loaded');

            $out = $w->instance()->weightChips('out');
            $this->assertCount(1, $out, "{$tag}: the weigh-out chip is offered once something is loaded");
            $w->call('setWeight', 'out', $out[0]['kg'])->assertSet('weighOutData.kg', $out[0]['kg'])->call('weighOut')->assertNotified('Weighed out');
            $this->assertSame(VisitStatus::WeighedOut, $status(), "{$tag}: weigh out");
        }

        if ($status() === VisitStatus::WeighedOut) {
            $w->assertSee('Release the truck')->call('gateOut')->assertNotified('Truck has left site');
        }

        $this->assertSame(VisitStatus::Departed, $status(), "{$tag}: the truck left the site");
    }

    /** Gate a free truck in through the order page, then take it all the way out. Returns nothing; the visit is the latest one. */
    private function runFreshTruck(Testable $w, DeliveryOrder $order, Truck $truck, ?Driver $driver, Tank $tank, string $tag): TruckVisit
    {
        $w->call('startGateIn')->set('gateInData.truck_id', $truck->id);
        if ($driver) {
            $w->set('gateInData.driver_id', $driver->id);
        }
        $w->call('gateIn')->assertNotified('Truck gated in');

        $visit = $this->visitOf($order, $truck->plate);
        $this->assertSame(VisitStatus::Arrived, $visit->status, "{$tag}: gate in");
        $this->advanceTruck($w, $visit, $tank, $tag);

        return $visit->fresh();
    }

    /** Half-up 0.5% of a gross quantity, in whole kg. */
    private function halfUpLoss(int $gross): int
    {
        return intdiv($gross * 5 + 500, 1000);
    }

    // ---- ACT 1 --------------------------------------------------------------------------------------------------

    /** screen => roles that may open it (everyone else must get 403) */
    private function screens(): array
    {
        return [
            'dashboard' => ['filament.admin.pages.dashboard', self::ALL],
            'vessel-discharges' => ['filament.admin.resources.vessel-discharges.index', self::ALL],
            'delivery-orders' => ['filament.admin.resources.delivery-orders.index', self::ALL],
            'cargo-movements' => ['filament.admin.resources.cargo-movements.index', self::ALL],
            'truck-visits' => ['filament.admin.resources.truck-visits.index', self::ALL],
            'tanks' => ['filament.admin.resources.tanks.index', self::ALL],
            'vessels' => ['filament.admin.resources.vessels.index', self::MANAGERS],
            'berths' => ['filament.admin.resources.berths.index', self::MANAGERS],
            'customers' => ['filament.admin.resources.customers.index', self::MANAGERS],
            'transporters' => ['filament.admin.resources.transporters.index', self::MANAGERS],
            'trucks' => ['filament.admin.resources.trucks.index', self::MANAGERS],
            'drivers' => ['filament.admin.resources.drivers.index', self::MANAGERS],
            'correction-requests' => ['filament.admin.resources.correction-requests.index', self::OVERSEERS],
            'audit-logs' => ['filament.admin.resources.audit-logs.index', self::OVERSEERS],
            'users' => ['filament.admin.resources.users.index', self::ADMIN],
            'reports' => ['filament.admin.pages.reports', self::OVERSEERS],
        ];
    }

    /** widget => roles that see it on the dashboard */
    private function widgets(): array
    {
        return [
            StockOverview::class => self::ALL,
            TankGauges::class => self::ALL,
            VesselsInPort::class => self::ALL,
            TrucksOnSite::class => self::ALL,
            InventoryTrend::class => self::OVERSEERS,
            RecentMovements::class => self::OVERSEERS,
        ];
    }

    private function act1(): void
    {
        $movement = CargoMovement::orderBy('id')->firstOrFail();

        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            $who = "ACT 1 {$role->value}";

            foreach ($this->screens() as $slug => [$route, $allowed]) {
                $open = in_array($role->value, $allowed, true);
                $response = $this->get(route($route));
                $this->assertSame($open ? 200 : 403, $response->getStatusCode(), "{$who}: /{$slug} should ".($open ? 'open' : 'be refused'));
                if ($open) {
                    $response->assertDontSee('How the terminal works');
                }
            }

            // one movement view page
            $view = $this->get(route('filament.admin.resources.cargo-movements.view', ['record' => $movement->id]));
            $view->assertOk()->assertDontSee('How the terminal works');

            // the dashboard widgets this role gets, according to the panel itself and according to the screen
            $expected = collect($this->widgets())->filter(fn ($roles) => in_array($role->value, $roles, true))->keys()->sort()->values()->all();
            $offered = collect(Filament::getPanel('admin')->getWidgets())
                ->reject(fn ($w) => str_starts_with((string) $w, 'Filament\\'))
                ->filter(fn ($w) => $w::canView())->sort()->values()->all();
            $this->assertSame($expected, $offered, "{$who}: widget set");

            $page = Livewire::test(Dashboard::class)->assertDontSee('How the terminal works');
            foreach ($this->widgets() as $widget => $roles) {
                in_array($role->value, $roles, true) ? $page->assertSeeLivewire($widget) : $page->assertDontSeeLivewire($widget);
            }
        }
    }

    // ---- ACT 2 --------------------------------------------------------------------------------------------------

    private function act2(): void
    {
        $this->loginAs(Role::Supervisor);
        $voyage = $this->voyage('VOY-2611-16');                       // PFAD, 800,000 kg on the bill of lading, berthed at B2
        $pfad = $this->tank('PFAD-01');
        $b2 = Berth::where('code', 'B2')->firstOrFail();
        $levelBefore = $pfad->current_volume_kg;
        $ledgerBefore = CargoMovement::count();

        $this->assertSame(DischargeStatus::Berthed, $voyage->status, 'ACT 2 step 1: the ship starts berthed');
        $this->assertSame($b2->id, $voyage->berth_id);

        // 2.1 press start
        $w = $this->wizard(RunDischarge::class, $voyage->id)->assertSee('Start the discharge')->call('start')->assertNotified('Discharge started')
            ->assertSee('Pump into a tank')->assertDontSee('Finish pumping');
        $this->assertSame(DischargeStatus::Discharging, $voyage->fresh()->status, 'ACT 2 step 2: discharging');

        // 2.2 pump the whole bill of lading with the chip
        $w->set('pumpData.tank_id', $pfad->id);
        $chips = $w->instance()->quantityChips();
        $whole = end($chips)['kg'];
        $this->assertSame(800_000, $whole, 'ACT 2 step 3: the last chip is the whole bill of lading');
        $this->assertContains($whole, array_column($chips, 'kg'));
        $w->call('setQuantity', $whole)->assertSet('pumpData.quantity_kg', 800_000)->call('pump')->assertNotified('Cargo pumped into tank');

        // 2.3 'Finish pumping' only now that everything is in, never the early variant
        $w->assertSee('Finish pumping')->assertDontSee('Finish pumping early');

        // 2.4 finish: the complete text shows what really arrived
        $w->call('finishPumping')->assertDontSee('Finish pumping early')
            ->assertSee('Complete the voyage')
            ->assertSee('796,000 kg received in the tanks, after 4,000 kg lost in transit (800,000 kg was pumped from the ship)');

        // 2.5 complete
        $w->call('complete')->assertNotified('Voyage completed')->assertSee('Voyage complete');

        $voyage->refresh();
        $this->assertSame(DischargeStatus::Completed, $voyage->status, 'ACT 2 step 6: completed');
        $this->assertNull($voyage->completion_note, 'ACT 2: a full discharge needs no reason');
        $this->assertNull($b2->fresh()->occupant(), 'ACT 2 step 6: berth B2 is free again');
        $this->assertSame($levelBefore + 796_000, $this->level('PFAD-01'), 'ACT 2 step 7: tank level');

        $rows = CargoMovement::where('vessel_discharge_id', $voyage->id)->get();
        $this->assertCount(1, $rows, 'ACT 2: one ledger row');
        $this->assertSame($ledgerBefore + 1, CargoMovement::count());
        $row = $rows->first();
        $this->assertSame($pfad->id, $row->tank_id);
        $this->assertSame(800_000, $row->gross_kg);
        $this->assertSame(4_000, $row->loss_kg, 'ACT 2: loss is exactly 0.5%');
        $this->assertSame(796_000, $row->net_kg);
        $this->assertSame($this->halfUpLoss($row->gross_kg), $row->loss_kg, 'ACT 2: loss is half-up of gross');
    }

    // ---- ACT 3 --------------------------------------------------------------------------------------------------

    private function act3(): void
    {
        $this->loginAs(Role::Supervisor);
        $customer = Customer::where('name', 'Kapa Oil Refineries')->firstOrFail();
        $cpol = $this->tank('CPOL-01');
        $levelBefore = $cpol->current_volume_kg;
        $truck = Truck::where('plate', 'KDG 481B')->firstOrFail();           // free, 30,000 kg payload
        $driver = Driver::where('name', 'Esther Chebet')->firstOrFail();
        $truck->update(['tare_kg' => 14_100]);                                 // the usual empty weight the chip will offer

        // 3.1 create the order on the create page
        $create = Livewire::test(CreateDeliveryOrder::class)
            ->fillForm(['customer_id' => $customer->id, 'commodity' => Commodity::CPOL->value, 'ordered_kg' => 40_000])
            ->call('create')->assertHasNoFormErrors();
        $order = DeliveryOrder::latest('id')->firstOrFail();
        $this->assertSame(OrderStatus::Pending, $order->status, 'ACT 3 step 1: new order is pending');
        $this->assertSame(40_000, $order->ordered_kg);
        $create->assertRedirect(route('filament.admin.resources.delivery-orders.run', ['record' => $order->id]));

        // 3.2 open loading
        $w = $this->wizard(RunDelivery::class, $order->id)->assertSee('Open loading')->call('openLoading')->assertNotified('Loading opened');
        $this->assertSame(OrderStatus::Loading, $order->fresh()->status, 'ACT 3 step 2: loading opened');

        // 3.3 first truck: gate in, weigh in (chip), start, load (chip), weigh out (chip), gate out
        $this->runFreshTruck($w, $order, $truck, $driver, $cpol, 'ACT 3 first truck');
        $this->assertSame(30_000, $order->fresh()->deliveredKg(), 'ACT 3: the truck took 30,000 kg');

        // 3.4 part loaded and nobody on site: only the quiet short close is on offer
        $w->assertDontSee('Complete the order')->assertSee('30,000 of 40,000 kg loaded so far')->assertSee('Close the order short');

        // 3.5 a second truck takes the rest; now the full button replaces the short close
        $this->runFreshTruck($w, $order, $truck, $driver, $cpol, 'ACT 3 second truck');
        $this->assertSame(40_000, $order->fresh()->deliveredKg(), 'ACT 3: the order is fully loaded');
        $w->assertSee('All 40,000 kg is loaded.')->assertSee('Complete the order')->assertDontSee('Close the order short');

        // 3.6 complete
        $w->call('completeOrder')->assertNotified('Order completed')->assertSee('Order complete');
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status, 'ACT 3 step 6: completed');
        $this->assertSame($levelBefore - 40_000, $this->level('CPOL-01'), 'ACT 3 step 6: tank level');
    }

    // ---- ACT 4 --------------------------------------------------------------------------------------------------

    private function act4(): void
    {
        $operator = $this->loginAs(Role::Operator);
        $o7 = $this->order('ORD-2007');                                         // loading, CPO
        $o8 = $this->order('ORD-2008');                                         // pending, CPOL
        $cpo = $this->tank('CPO-01');
        $cpol = $this->tank('CPOL-01');
        $ledgerBefore = CargoMovement::count();

        $w = $this->wizard(RunDelivery::class, $o7->id);

        // 4.1 gate out the truck that was already weighed out
        $weighedOut = $this->visitOf($o7, 'KDE 055V');
        $this->assertSame(VisitStatus::WeighedOut, $weighedOut->status);
        $w->call('selectVisit', $weighedOut->id)->assertSee('Release the truck')->call('gateOut')->assertNotified('Truck has left site');
        $this->assertSame(VisitStatus::Departed, $weighedOut->fresh()->status, 'ACT 4 step 1: gate out');

        // 4.2 the truck part-loaded on the bay finishes: load (chip), weigh out (chip), gate out
        $this->advanceTruck($w, $this->visitOf($o7, 'KDB 118Q'), $cpo, 'ACT 4 step 2 KDB 118Q');

        // 4.3 the truck that just arrived goes through every step
        $this->advanceTruck($w, $this->visitOf($o7, 'KDC 702R'), $cpo, 'ACT 4 step 3 KDC 702R');

        // 4.4 the pending order: open loading, then its arrived truck goes through
        $w8 = $this->wizard(RunDelivery::class, $o8->id)->call('openLoading')->assertNotified('Loading opened');
        $this->assertSame(OrderStatus::Loading, $o8->fresh()->status, 'ACT 4 step 4: ORD-2008 loading');
        $this->advanceTruck($w8, $this->visitOf($o8, 'KCY 227U'), $cpol, 'ACT 4 step 4 KCY 227U');

        $this->assertSame($ledgerBefore + 3, CargoMovement::count(), 'ACT 4: three loads were booked');

        // 4.5 refused: creating a voyage
        $voyages = VesselDischarge::count();
        $this->get(route('filament.admin.resources.vessel-discharges.create'))->assertForbidden();
        Livewire::test(CreateVesselDischarge::class)->assertForbidden();
        Livewire::test(ListVesselDischarges::class)->assertActionHidden('create');
        $this->assertSame($voyages, VesselDischarge::count(), 'ACT 4 step 5: no voyage was created');

        // 4.6 refused: archiving or deleting register records
        $tank = $this->tank('CPOL-01');
        $this->assertFalse($operator->can('archive', $tank), 'ACT 4 step 6: archive');
        $this->assertFalse($operator->can('delete', $tank));
        $this->assertFalse($operator->can('deleteAny', Tank::class));
        Livewire::test(ListTanks::class)->assertActionHidden($this->row('archive', $tank))->assertActionHidden($this->row('delete', $tank));
        foreach (['archive', 'delete'] as $action) {
            $this->assertRefusedAndNothingChanged(
                fn () => Livewire::test(ListTanks::class)->call('mountTableAction', $action, $tank->id)->call('callMountedTableAction'),
                "ACT 4 step 6: operator presses {$action} on a tank",
            );
        }
        $this->get(route('filament.admin.resources.vessels.index'))->assertForbidden();
        Livewire::test(ListVessels::class)->assertForbidden();

        // 4.7 refused: opening users and the corrections queue
        $this->get(route('filament.admin.resources.users.index'))->assertForbidden();
        Livewire::test(ListUsers::class)->assertForbidden();
        $this->get(route('filament.admin.resources.correction-requests.index'))->assertForbidden();
        Livewire::test(ListCorrectionRequests::class)->assertForbidden();

        // 4.8 the operator files a correction through the ledger list
        $movement = CargoMovement::where('idempotency_key', 'dy-load-2')->firstOrFail();
        Livewire::test(ListCargoMovements::class)
            ->callAction($this->row('request_correction', $movement), data: ['reason' => 'The 18,000 kg was booked from the wrong tank.'])
            ->assertNotified('Correction requested: waiting for a supervisor');
        $request = CorrectionRequest::where('cargo_movement_id', $movement->id)->firstOrFail();
        $this->assertSame(CorrectionStatus::Pending, $request->status, 'ACT 4 step 8: pending');
        $this->assertSame($operator->id, $request->requester->id);
        Livewire::test(ListCargoMovements::class)->assertActionHidden($this->row('request_correction', $movement->fresh()));

        // 4.9 and cannot approve it, not through the screen and not by calling the action
        $this->assertRefusedAndNothingChanged(
            fn () => Livewire::test(ListCorrectionRequests::class)->call('mountTableAction', 'approve', $request->id)->call('callMountedTableAction'),
            'ACT 4 step 9: operator presses approve',
        );
        try {
            app(ApproveCorrection::class)($request->fresh(), $operator, 'approving my own request');
            $this->fail('ACT 4 step 9: the operator approved their own correction');
        } catch (NotAuthorizedException) {
            $this->assertSame(CorrectionStatus::Pending, $request->fresh()->status);
        }
    }

    // ---- ACT 5 --------------------------------------------------------------------------------------------------

    private function act5(): void
    {
        // the operator files one more correction (the way act 4 does), then the supervisor decides
        $this->loginAs(Role::Operator);
        $movement = CargoMovement::where('idempotency_key', 'dy-load-1')->firstOrFail();      // 30,000 kg from CPO-01 onto ORD-2007
        Livewire::test(ListCargoMovements::class)
            ->callAction($this->row('request_correction', $movement), data: ['reason' => 'Wrong tank, this should have been loaded elsewhere.'])
            ->assertNotified('Correction requested: waiting for a supervisor');
        $toApprove = CorrectionRequest::where('cargo_movement_id', $movement->id)->firstOrFail();
        $toReject = CorrectionRequest::where('status', 'pending')->where('id', '!=', $toApprove->id)
            ->whereHas('movement', fn ($q) => $q->where('idempotency_key', 'dy-out-2'))->firstOrFail();      // the one in the demo seed

        $this->loginAs(Role::Supervisor);
        $order = $this->order('ORD-2007');
        $report = fn () => collect(app(StockPositionReport::class)(Carbon::parse('2000-01-01'), now()->addDay())['rows'])->keyBy(fn ($r) => $r['commodity']->value);
        $levelBefore = $this->level('CPO-01');
        $deliveredBefore = DeliveryOrder::withTotals()->find($order->id)->deliveredKg();
        $reportBefore = $report()['cpo'];
        $ledgerBefore = CargoMovement::count();
        $cpolBefore = $this->level('CPOL-01');

        $queue = Livewire::test(ListCorrectionRequests::class)->assertCanSeeTableRecords([$toApprove, $toReject]);

        // 5.1 approve
        $queue->callAction($this->row('approve', $toApprove), data: ['note' => 'Checked against the weighbridge ticket.'])
            ->assertNotified('Correction approved: reversal recorded');
        $toApprove->refresh();
        $this->assertSame(CorrectionStatus::Approved, $toApprove->status, 'ACT 5 step 1: approved');
        $reversals = CargoMovement::where('reverses_movement_id', $movement->id)->get();
        $this->assertCount(1, $reversals, 'ACT 5 step 1: a reversal row exists');
        $this->assertSame($ledgerBefore + 1, CargoMovement::count(), 'ACT 5: the ledger only grows');
        $this->assertTrue($movement->fresh()->isReversed(), 'ACT 5 step 1: the original is marked reversed');
        $this->assertSame($reversals->first()->id, $toApprove->reversal_movement_id);
        $this->assertSame($levelBefore + 30_000, $this->level('CPO-01'), 'ACT 5 step 2: the cargo is back in the tank');
        $this->assertSame($deliveredBefore - 30_000, DeliveryOrder::withTotals()->find($order->id)->deliveredKg(), 'ACT 5 step 2: the order delivered total went down');

        $after = $report()['cpo'];
        $this->assertTrue($after['reconciles'], 'ACT 5 step 3: the stock report reconciles');
        $this->assertSame($reportBefore['delivered_kg'] - 30_000, $after['delivered_kg'], 'ACT 5 step 3: report delivered');
        $this->assertSame($reportBefore['closing_kg'] + 30_000, $after['closing_kg'], 'ACT 5 step 3: report closing');
        $this->assertSame((int) Tank::where('commodity', 'cpo')->sum('current_volume_kg'), $after['closing_kg'], 'ACT 5 step 3: report equals the tanks');

        // 5.2 reject another one, a reason is required
        $queue->callAction($this->row('reject', $toReject), data: ['note' => ''])->assertHasActionErrors(['note']);
        $this->assertSame(CorrectionStatus::Pending, $toReject->fresh()->status, 'ACT 5 step 4: no reason, no rejection');
        Livewire::test(ListCorrectionRequests::class)->callAction($this->row('reject', $toReject), data: ['note' => 'The ticket matches, no error.'])->assertNotified('Correction rejected');
        $toReject->refresh();
        $this->assertSame(CorrectionStatus::Rejected, $toReject->status, 'ACT 5 step 4: rejected');
        $this->assertSame('The ticket matches, no error.', $toReject->decision_note);
        $this->assertNull(CargoMovement::where('reverses_movement_id', $toReject->cargo_movement_id)->first(), 'ACT 5: a rejection reverses nothing');
        $this->assertSame($cpolBefore, $this->level('CPOL-01'), 'ACT 5: the rejected movement still stands');
        $this->assertSame($ledgerBefore + 1, CargoMovement::count());

        // 5.3 a second approval is refused
        $queue = Livewire::test(ListCorrectionRequests::class)->removeTableFilter('status');
        $queue->assertActionHidden($this->row('approve', $toApprove->fresh()))->assertActionHidden($this->row('approve', $toReject->fresh()));
        $this->assertRefusedAndNothingChanged(
            fn () => Livewire::test(ListCorrectionRequests::class)->removeTableFilter('status')->call('mountTableAction', 'approve', $toApprove->id)->call('callMountedTableAction'),
            'ACT 5 step 5: a second approval through the screen',
        );
        try {
            app(ApproveCorrection::class)($toApprove->fresh(), auth()->user(), 'again');
            $this->fail('ACT 5 step 5: a decided request was approved a second time');
        } catch (CorrectionAlreadyDecidedException) {
            $this->assertSame($ledgerBefore + 1, CargoMovement::count());
        }
    }

    // ---- ACT 6 --------------------------------------------------------------------------------------------------

    private function act6(): void
    {
        $this->loginAs(Role::Auditor);
        $before = $this->snapshot();

        $discharging = $this->voyage('VOY-2611-15');
        $berthed = $this->voyage('VOY-2611-16');
        $scheduled = $this->voyage('VOY-2611-17');
        $o7 = $this->order('ORD-2007');
        $o9 = $this->order('ORD-2009');
        $loadingVisit = $this->visitOf($o7, 'KDB 118Q');
        $arrivedVisit = $this->visitOf($o7, 'KDC 702R');
        $weighedOut = $this->visitOf($o7, 'KDE 055V');
        $pfad = $this->tank('PFAD-01');
        $cpo = $this->tank('CPO-01');
        $movement = CargoMovement::where('idempotency_key', 'dy-in-1-1')->firstOrFail();
        $pending = CorrectionRequest::where('status', 'pending')->firstOrFail();

        // 6.1 every wizard method is refused
        $pump = fn () => $this->wizard(RunDischarge::class, $discharging->id)->set('pumpData.tank_id', $cpo->id)->set('pumpData.quantity_kg', 1_000)
            ->set('pumpData.occurred_at', now()->toDateTimeString())->set('pumpData.idempotency_key', 'auditor-pump')->call('pump');
        $load = fn () => $this->wizard(RunDelivery::class, $o7->id)->call('selectVisit', $loadingVisit->id)->set('loadData.tank_id', $cpo->id)
            ->set('loadData.quantity_kg', 1_000)->set('loadData.occurred_at', now()->toDateTimeString())->set('loadData.idempotency_key', 'auditor-load')->call('load');
        $attempts = [
            'pump' => $pump,
            'berth' => fn () => $this->wizard(RunDischarge::class, $scheduled->id)->set('berthData.berth_id', Berth::where('code', 'B3')->value('id'))->call('berth'),
            'start the discharge' => fn () => $this->wizard(RunDischarge::class, $berthed->id)->call('start'),
            'complete the voyage' => fn () => $this->wizard(RunDischarge::class, $discharging->id)->call('finishPumping')->call('complete'),
            'cancel the voyage' => fn () => $this->wizard(RunDischarge::class, $scheduled->id)->call('mountAction', 'cancel_voyage')->call('callMountedAction'),
            'pick a pump chip' => fn () => $this->wizard(RunDischarge::class, $discharging->id)->call('setQuantity', 1),
            'open loading' => fn () => $this->wizard(RunDelivery::class, $o9->id)->call('openLoading'),
            'gate in' => fn () => $this->wizard(RunDelivery::class, $o7->id)->set('gateInData.truck_id', Truck::where('plate', 'KDG 481B')->value('id'))->call('gateIn'),
            'weigh in' => fn () => $this->wizard(RunDelivery::class, $o7->id)->call('selectVisit', $arrivedVisit->id)->set('weighInData.kg', 14_000)->call('weighIn'),
            'start truck loading' => fn () => $this->wizard(RunDelivery::class, $o7->id)->call('selectVisit', $arrivedVisit->id)->call('startTruckLoading'),
            'load' => $load,
            'weigh out' => fn () => $this->wizard(RunDelivery::class, $o7->id)->call('selectVisit', $loadingVisit->id)->set('weighOutData.kg', 40_000)->call('weighOut'),
            'weigh chip' => fn () => $this->wizard(RunDelivery::class, $o7->id)->call('selectVisit', $arrivedVisit->id)->call('setWeight', 'in', 14_000),
            'gate out' => fn () => $this->wizard(RunDelivery::class, $o7->id)->call('selectVisit', $weighedOut->id)->call('gateOut'),
            'complete the order' => fn () => $this->wizard(RunDelivery::class, $o7->id)->call('completeOrder'),
            'cancel the order' => fn () => $this->wizard(RunDelivery::class, $o7->id)->call('mountAction', 'cancel_order')->call('callMountedAction'),
        ];
        foreach ($attempts as $what => $attempt) {
            $this->assertRefusedAndNothingChanged($attempt, "ACT 6 step 1: auditor tries to {$what}");
        }
        foreach (['pump', 'berth', 'start the discharge', 'complete the voyage', 'open loading', 'gate in', 'weigh in', 'load', 'weigh out', 'gate out', 'complete the order'] as $what) {
            try {
                $attempts[$what]()->assertForbidden();
            } catch (Throwable $e) {
                $this->fail("ACT 6 step 1: auditor was not refused (403) when trying to {$what}: ".$e->getMessage());
            }
        }

        // 6.2 list-page actions: hidden, and crafted presses change nothing
        Livewire::test(ListCargoMovements::class)->assertActionHidden($this->row('request_correction', $movement));
        $this->assertRefusedAndNothingChanged(
            fn () => Livewire::test(ListCargoMovements::class)->callAction($this->row('request_correction', $movement), data: ['reason' => 'Crafted request from an auditor.']),
            'ACT 6 step 2: auditor requests a correction',
        );
        $queue = Livewire::test(ListCorrectionRequests::class)->assertCanSeeTableRecords([$pending]);
        $queue->assertActionHidden($this->row('approve', $pending))->assertActionHidden($this->row('reject', $pending));
        foreach (['approve', 'reject'] as $action) {
            $this->assertRefusedAndNothingChanged(
                fn () => Livewire::test(ListCorrectionRequests::class)->call('mountTableAction', $action, $pending->id)->call('callMountedTableAction'),
                "ACT 6 step 2: auditor presses {$action}",
            );
        }
        Livewire::test(ListTanks::class)->assertActionHidden('create')->assertActionHidden($this->row('archive', $pfad))->assertActionHidden($this->row('delete', $pfad))->assertActionHidden($this->row('edit', $pfad));
        Livewire::test(ListVesselDischarges::class)->assertActionHidden('create');

        // 6.3 nothing changed anywhere: the ledger, the tank volumes, every status
        $this->assertSame($before, $this->snapshot(), 'ACT 6: the auditor changed something');
        $this->assertSame(0, CargoMovement::whereIn('idempotency_key', ['auditor-pump', 'auditor-load'])->count());

        // 6.4 every page the auditor may see opens, and the read-only note shows on the order and voyage pages
        $opens = [
            route('filament.admin.pages.dashboard'),
            route('filament.admin.pages.reports'),
            route('filament.admin.resources.vessel-discharges.index'),
            route('filament.admin.resources.vessel-discharges.view', ['record' => $discharging->id]),
            route('filament.admin.resources.vessel-discharges.run', ['record' => $discharging->id]),
            route('filament.admin.resources.delivery-orders.index'),
            route('filament.admin.resources.delivery-orders.view', ['record' => $o7->id]),
            route('filament.admin.resources.delivery-orders.run', ['record' => $o7->id]),
            route('filament.admin.resources.truck-visits.index'),
            route('filament.admin.resources.truck-visits.view', ['record' => $loadingVisit->id]),
            route('filament.admin.resources.cargo-movements.index'),
            route('filament.admin.resources.cargo-movements.view', ['record' => $movement->id]),
            route('filament.admin.resources.tanks.index'),
            route('filament.admin.resources.tanks.view', ['record' => $pfad->id]),
            route('filament.admin.resources.correction-requests.index'),
            route('filament.admin.resources.correction-requests.view', ['record' => $pending->id]),
            route('filament.admin.resources.audit-logs.index'),
            route('filament.admin.resources.audit-logs.view', ['record' => AuditLog::firstOrFail()->id]),
        ];
        foreach ($opens as $url) {
            $this->get($url)->assertOk();
        }
        $this->wizard(RunDelivery::class, $o7->id)->assertSee('You can look at this order but not change it');
        $this->wizard(RunDischarge::class, $discharging->id)->assertSee('You can look at this voyage but not change it');
    }

    // ---- ACT 7 --------------------------------------------------------------------------------------------------

    private function act7(): void
    {
        $admin = $this->loginAs(Role::Admin);

        // 7.1 create an empty spare tank on the create page
        Livewire::test(CreateTank::class)
            ->fillForm(['name' => 'Spare CPO tank', 'commodity' => Commodity::CPO->value, 'capacity_kg' => 100_000])
            ->call('create')->assertHasNoFormErrors();
        $spare = Tank::latest('id')->firstOrFail();
        $this->assertSame(0, $spare->current_volume_kg, 'ACT 7 step 1: the spare tank is empty');
        Livewire::test(TankGauges::class)->assertSee($spare->code);

        // 7.2 archive it: it leaves the dashboard gauges
        Livewire::test(ListTanks::class)->callAction($this->row('archive', $spare))->assertNotified('Archived');
        $this->assertFalse($spare->fresh()->active, 'ACT 7 step 2: archived');
        Livewire::test(TankGauges::class)->assertDontSee($spare->code);

        // 7.3 booking to it is refused with the friendly message
        $ledger = CargoMovement::count();
        try {
            app(RecordVesselDischarge::class)($spare->id, 10_000, now(), 'act7-archived', 'MV Test');
            $this->fail('ACT 7 step 3: an archived tank took a movement');
        } catch (TankArchivedException $e) {
            $this->assertSame("Tank {$spare->code} is archived and cannot take new movements. An admin can restore it.", $e->getMessage());
        }
        $this->assertSame($ledger, CargoMovement::count());
        $this->assertSame(0, $spare->fresh()->current_volume_kg);

        // and the pump screen does not take it either
        $voyage = $this->voyage('VOY-2611-15');
        $this->wizard(RunDischarge::class, $voyage->id)->set('pumpData.tank_id', $spare->id)->set('pumpData.quantity_kg', 1_000)
            ->set('pumpData.occurred_at', now()->toDateTimeString())->set('pumpData.idempotency_key', 'act7-pump')->call('pump');
        $this->assertSame($ledger, CargoMovement::count(), 'ACT 7 step 3: the pump screen booked nothing into the archived tank');

        // 7.4 a tank with ledger rows cannot be deleted, and says why
        $busy = $this->tank('CPOL-01');
        $rows = $busy->movements()->count();
        $this->assertGreaterThan(0, $rows);
        $busy->update(['active' => false]);
        Livewire::test(ListTanks::class)->removeTableFilter('active')->callAction($this->row('delete', $busy->fresh()))->assertNotified('Not done');
        $this->assertNotNull(Tank::find($busy->id), 'ACT 7 step 4: a tank with ledger rows survives delete');
        $this->assertSame($rows, $busy->movements()->count());
        $busy->update(['active' => true]);

        // 7.5 only an admin may archive or delete
        $this->loginAs(Role::Supervisor);
        Livewire::test(ListTanks::class)->removeTableFilter('active')
            ->assertActionHidden($this->row('delete', $spare->fresh()))->assertActionHidden($this->row('restore', $spare->fresh()))
            ->assertActionHidden($this->row('archive', $this->tank('PFAD-01')));
        $this->assertTrue($this->tank('PFAD-01')->active);

        // 7.6 restore, archive again, delete
        $this->loginAs(Role::Admin);
        Livewire::test(ListTanks::class)->removeTableFilter('active')->callAction($this->row('restore', $spare->fresh()))->assertNotified('Restored');
        $this->assertTrue($spare->fresh()->active, 'ACT 7 step 6: restored');
        Livewire::test(TankGauges::class)->assertSee($spare->code);
        Livewire::test(ListTanks::class)->callAction($this->row('archive', $spare->fresh()))->assertNotified('Archived');
        Livewire::test(ListTanks::class)->removeTableFilter('active')->callAction($this->row('delete', $spare->fresh()))->assertNotified('Deleted');
        $this->assertNull(Tank::find($spare->id), 'ACT 7 step 6: the spare tank is gone');

        // 7.7 the users page opens
        $this->get(route('filament.admin.resources.users.index'))->assertOk();
        $this->assertTrue($admin->role->canManageUsers());
    }

    // ---- the acts as tests ----------------------------------------------------------------------------------------

    public function test_act_1_every_user_opens_their_screens_and_is_refused_the_rest(): void
    {
        $this->act1();
    }

    public function test_act_2_supervisor_runs_a_complete_ship_with_the_chip(): void
    {
        $this->act2();
    }

    public function test_act_3_supervisor_takes_a_delivery_from_scratch(): void
    {
        $this->act3();
    }

    public function test_act_4_operator_runs_the_trucks_and_is_refused_admin_actions(): void
    {
        $this->act4();
    }

    public function test_act_5_supervisor_approves_one_correction_and_rejects_another(): void
    {
        $this->act5();
    }

    public function test_act_6_auditor_can_look_at_everything_and_change_nothing(): void
    {
        $this->act6();
    }

    public function test_act_7_admin_archives_restores_and_deletes_the_spare_tank(): void
    {
        $this->act7();
    }

    // ---- the whole demo in one database -----------------------------------------------------------------------------

    public function test_final_act_the_whole_demo_in_sequence_leaves_the_system_consistent(): void
    {
        $this->act2();
        $this->act3();
        $this->act4();
        $this->act5();
        $this->act6();
        $this->act7();

        // F1 the stock report reconciles for every commodity and equals what the tanks hold
        $this->loginAs(Role::Supervisor);
        $rows = app(StockPositionReport::class)(Carbon::parse('2000-01-01'), now()->addDay())['rows'];
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $code = $row['commodity']->value;
            $this->assertTrue($row['reconciles'], "FINAL: stock report for {$code} reconciles");
            $this->assertSame((int) Tank::where('commodity', $code)->sum('current_volume_kg'), $row['closing_kg'], "FINAL: report closing equals the {$code} tanks");
        }

        // F2 no tank outside 0..capacity
        foreach (Tank::all() as $tank) {
            $this->assertGreaterThanOrEqual(0, $tank->current_volume_kg, "FINAL: {$tank->code} below empty");
            $this->assertLessThanOrEqual($tank->capacity_kg, $tank->current_volume_kg, "FINAL: {$tank->code} over capacity");
        }

        // F3 the 0.5% loss is half-up of gross on every discharge row, and deliveries lose nothing
        $discharges = 0;
        foreach (CargoMovement::all() as $m) {
            if ($m->isReversal()) {
                continue;
            }
            if ($m->type->isInbound()) {
                $discharges++;
                $this->assertSame($this->halfUpLoss($m->gross_kg), $m->loss_kg, "FINAL: movement #{$m->id} loss");
                $this->assertSame($m->gross_kg - $m->loss_kg, $m->net_kg, "FINAL: movement #{$m->id} net");
            } else {
                $this->assertSame(0, $m->loss_kg, "FINAL: delivery #{$m->id} has no transit loss");
            }
        }
        $this->assertGreaterThan(0, $discharges);

        // F4 every audit entry written during the run names the person who did it, never the auditor
        $entries = AuditLog::where('id', '>', $this->auditFloor)->get();
        $this->assertGreaterThan(20, $entries->count(), 'FINAL: the run was audited');
        foreach ($entries as $e) {
            $this->assertNotNull($e->user_id, "FINAL: audit #{$e->id} ({$e->description}) has no user");
            $this->assertContains($e->actor_role, ['admin', 'supervisor', 'operator'], "FINAL: audit #{$e->id} ({$e->description}) was written as {$e->actor_role}");
            $user = User::findOrFail($e->user_id);
            $this->assertSame($user->name, $e->actor_name, "FINAL: audit #{$e->id} names the wrong person");
            $this->assertSame($user->role->value, $e->actor_role, "FINAL: audit #{$e->id} names the wrong role");
        }
        $this->assertSame(0, $entries->where('actor_role', 'auditor')->count());

        // F5 no admin page gives a server error for any role
        $this->assertNoServerErrors();
    }

    private function assertNoServerErrors(): void
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (str_starts_with($name, 'filament.admin.') && in_array('GET', $route->methods(), true) && ! in_array($name, ['filament.admin.auth.login', 'filament.admin.auth.logout'], true)) {
                $routes[$name] = $route->uri();
            }
        }
        ksort($routes);
        $this->assertGreaterThanOrEqual(40, count($routes));

        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            $ids = $this->sampleIds();
            foreach ($routes as $name => $uri) {
                $url = str_contains($uri, '{record}') ? '/'.str_replace('{record}', (string) $ids[explode('.', $name)[3]], $uri) : '/'.$uri;
                $status = $this->get($url)->getStatusCode();
                $this->assertContains($status, [200, 403], "FINAL: {$role->value} GET {$url} gave {$status}");
            }
        }
    }
}
