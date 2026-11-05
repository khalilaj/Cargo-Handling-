<?php

namespace Tests\Feature\Roles;

use App\Domain\Access\Role;
use App\Domain\Fleet\Actions\TruckVisitWorkflow;
use App\Domain\Operations\Actions\RecordTruckLoad;
use App\Filament\Resources\BerthResource\Pages\ListBerths;
use App\Filament\Resources\CargoMovementResource\Pages\ListCargoMovements;
use App\Filament\Resources\CorrectionRequestResource\Pages\ListCorrectionRequests;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\DeliveryOrderResource\Pages\ListDeliveryOrders;
use App\Filament\Resources\DeliveryOrderResource\Pages\ViewDeliveryOrder;
use App\Filament\Resources\DriverResource\Pages\ListDrivers;
use App\Filament\Resources\TankResource\Pages\ListTanks;
use App\Filament\Resources\TransporterResource\Pages\ListTransporters;
use App\Filament\Resources\TruckResource\Pages\ListTrucks;
use App\Filament\Resources\TruckVisitResource\Pages\ListTruckVisits;
use App\Filament\Resources\TruckVisitResource\Pages\ViewTruckVisit;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\VesselDischargeResource\Pages\ListVesselDischarges;
use App\Filament\Resources\VesselDischargeResource\Pages\ViewVesselDischarge;
use App\Filament\Resources\VesselResource\Pages\ListVessels;
use App\Models\CargoMovement;
use App\Models\CorrectionRequest;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\TruckVisit;
use App\Models\Vessel;
use App\Models\VesselDischarge;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/**
 * Which buttons does each role get, in EVERY state of voyage, order, truck visit, movement and correction?
 * And if someone tries to press a button they should not have, does the server refuse it?
 */
class ActionMatrixTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    private const ALL = ['admin', 'supervisor', 'operator', 'auditor'];

    private const OPERATE = ['admin', 'supervisor', 'operator'];

    private const MANAGERS = ['admin', 'supervisor'];

    private const OVERSEERS = ['admin', 'supervisor', 'auditor'];

    private const ADMIN = ['admin'];

    private const NONE = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWithEdgeStates();
    }

    /** Can this role even open the page? If not, mounting it must be refused. */
    private function openedBy(string $page, array $roles, Role $role): ?bool
    {
        return in_array($role->value, $roles, true);
    }

    /**
     * Mount $page as $role and check, for every action, that it is visible exactly to the roles listed.
     * A role that may not open the page must be refused instead.
     *
     * @param  array<string, list<string>>  $expect  action name => roles that see it
     */
    private function assertHeaderActions(string $page, array $params, array $opensFor, array $expect, string $context): void
    {
        foreach ($this->roles() as $role) {
            $this->loginAs($role);

            if (! in_array($role->value, $opensFor, true)) {
                $this->assertPageRefused($page, $params, "{$context}: {$role->value} must not open the page");

                continue;
            }

            $component = Livewire::test($page, $params);
            foreach ($expect as $action => $roles) {
                in_array($role->value, $roles, true)
                    ? $component->assertActionVisible($action)
                    : $component->assertActionHidden($action);
            }
        }
    }

    /** @param  list<array{record: object, action: string, roles: list<string>}>  $rows */
    private function assertRowActions(string $page, array $opensFor, array $rows, string $context, bool $removeFilters = false): void
    {
        foreach ($this->roles() as $role) {
            $this->loginAs($role);

            if (! in_array($role->value, $opensFor, true)) {
                $this->assertPageRefused($page, [], "{$context}: {$role->value} must not open the page");

                continue;
            }

            $component = Livewire::test($page);
            if ($removeFilters) {
                $component->removeTableFilter('status');
            }
            foreach ($rows as $row) {
                $target = TestAction::make($row['action'])->table($row['record']);
                in_array($role->value, $row['roles'], true)
                    ? $component->assertActionVisible($target)
                    : $component->assertActionHidden($target);
            }
        }
    }

    private function assertPageRefused(string $page, array $params, string $message): void
    {
        Livewire::test($page, $params)->assertForbidden();
    }

    private function voyage(string $ref): VesselDischarge
    {
        return VesselDischarge::where('voyage_ref', $ref)->firstOrFail();
    }

    private function order(string $ref): DeliveryOrder
    {
        return DeliveryOrder::where('order_ref', $ref)->firstOrFail();
    }

    private function visitOf(string $plate): TruckVisit
    {
        return TruckVisit::whereHas('truck', fn ($q) => $q->where('plate', $plate))->latest('id')->firstOrFail();
    }

    // ---- vessel discharges: every state ---------------------------------------------------------------------------------

    public function test_voyage_buttons_by_state_and_role(): void
    {
        $actions = ['to_berthed', 'to_discharging', 'pump', 'to_completed', 'to_cancelled'];
        $visibleIn = [
            'VOY-2611-05' => ['to_berthed', 'to_cancelled'],                          // scheduled
            'VOY-2611-04' => ['to_discharging', 'to_cancelled'],                      // berthed
            'VOY-2611-03' => ['pump', 'to_completed', 'to_cancelled'],                // discharging
            'VOY-2611-01' => [],                                                      // completed
            'VOY-EDGE-1' => [],                                                       // cancelled
        ];

        foreach ($visibleIn as $ref => $shown) {
            $expect = [];
            foreach ($actions as $a) {
                $expect[$a] = in_array($a, $shown, true) ? self::OPERATE : self::NONE;     // the auditor never gets one
            }

            $this->assertHeaderActions(ViewVesselDischarge::class, ['record' => $this->voyage($ref)->id], self::ALL, $expect, "voyage {$ref}");
        }
    }

    // ---- delivery orders ------------------------------------------------------------------------------------------------

    public function test_order_buttons_by_state_and_role(): void
    {
        $actions = ['to_loading', 'load', 'to_completed', 'to_cancelled'];
        $visibleIn = [
            'ORD-1004' => ['to_loading', 'to_cancelled'],                             // pending
            'ORD-1003' => ['load', 'to_completed', 'to_cancelled'],                   // loading
            'ORD-1001' => [],                                                         // completed
            'ORD-EDGE-1' => [],                                                       // cancelled
        ];

        foreach ($visibleIn as $ref => $shown) {
            $expect = [];
            foreach ($actions as $a) {
                $expect[$a] = in_array($a, $shown, true) ? self::OPERATE : self::NONE;
            }

            $this->assertHeaderActions(ViewDeliveryOrder::class, ['record' => $this->order($ref)->id], self::ALL, $expect, "order {$ref}");
        }
    }

    // ---- truck visits: every state --------------------------------------------------------------------------------------

    public function test_truck_visit_buttons_by_state_and_role(): void
    {
        // a visit that has been weighed out (the one state the demo data does not contain)
        $flow = app(TruckVisitWorkflow::class);
        $truck = Truck::create(['plate' => 'KEDGE 3C', 'capacity_kg' => 30_000, 'tare_kg' => 14_000]);
        $visit = $flow->startLoading($flow->weighIn($flow->gateIn($this->order('ORD-1003'), $truck), 14_000));
        app(RecordTruckLoad::class)($this->order('ORD-1003'), Tank::where('code', 'CPOL-01')->value('id'), 5_000, now(), 'weighed-out-load', null, $visit);
        $flow->weighOut($visit, 19_000);

        $actions = ['weigh_in', 'start_loading', 'load_visit', 'weigh_out', 'gate_out', 'cancel_visit'];
        $visibleIn = [
            'KCB 887L' => ['weigh_in', 'cancel_visit'],                                // arrived
            'KDA 999Z' => ['start_loading', 'cancel_visit'],                           // weighed in
            'KCD 452M' => ['load_visit', 'weigh_out'],                                 // loading
            'KEDGE 3C' => ['gate_out'],                                                // weighed out
            'KEDGE 1A' => [],                                                          // departed
            'KEDGE 2B' => [],                                                          // cancelled
        ];

        foreach ($visibleIn as $plate => $shown) {
            $expect = [];
            foreach ($actions as $a) {
                $expect[$a] = in_array($a, $shown, true) ? self::OPERATE : self::NONE;
            }

            $this->assertHeaderActions(ViewTruckVisit::class, ['record' => $this->visitOf($plate)->id], self::ALL, $expect, "visit {$plate}");
        }
    }

    // ---- list pages: header and row buttons ---------------------------------------------------------------------------

    public function test_movement_screen_buttons(): void
    {
        $this->assertHeaderActions(ListCargoMovements::class, [], self::ALL, ['discharge' => self::OPERATE, 'delivery' => self::OPERATE], 'movements header');

        $free = CargoMovement::where('idempotency_key', 'seed-1')->first();                  // can be corrected
        $pending = CargoMovement::where('idempotency_key', 'seed-5')->first();               // already has an open request
        $reversed = CargoMovement::where('idempotency_key', 'edge-load-1')->first();         // already reversed
        $reversal = CargoMovement::whereNotNull('reverses_movement_id')->first();            // a reversal cannot be corrected

        $this->assertRowActions(ListCargoMovements::class, self::ALL, [
            ['record' => $free, 'action' => 'request_correction', 'roles' => self::OPERATE],
            ['record' => $pending, 'action' => 'request_correction', 'roles' => self::NONE],
            ['record' => $reversed, 'action' => 'request_correction', 'roles' => self::NONE],
            ['record' => $reversal, 'action' => 'request_correction', 'roles' => self::NONE],
        ], 'movements rows');
    }

    public function test_gate_in_button(): void
    {
        $this->assertHeaderActions(ListTruckVisits::class, [], self::ALL, ['gate_in' => self::OPERATE], 'truck visits header');
    }

    public function test_correction_queue_buttons_by_state(): void
    {
        $pending = CorrectionRequest::where('status', 'pending')->first();
        $approved = CorrectionRequest::where('status', 'approved')->first();
        $rejected = CorrectionRequest::where('status', 'rejected')->first();

        $this->assertRowActions(ListCorrectionRequests::class, self::OVERSEERS, [
            ['record' => $pending, 'action' => 'approve', 'roles' => self::MANAGERS],         // approvers only: an auditor observes
            ['record' => $pending, 'action' => 'reject', 'roles' => self::MANAGERS],
            ['record' => $approved, 'action' => 'approve', 'roles' => self::NONE],            // decided requests are final
            ['record' => $approved, 'action' => 'reject', 'roles' => self::NONE],
            ['record' => $rejected, 'action' => 'approve', 'roles' => self::NONE],
        ], 'corrections', true);
    }

    public function test_create_and_edit_buttons_follow_master_data_rights(): void
    {
        // Operations lists: everyone opens them, only managers can schedule / create
        $this->assertHeaderActions(ListVesselDischarges::class, [], self::ALL, ['create' => self::MANAGERS], 'voyages header');
        $this->assertHeaderActions(ListDeliveryOrders::class, [], self::ALL, ['create' => self::MANAGERS], 'orders header');
        $this->assertHeaderActions(ListTanks::class, [], self::ALL, ['create' => self::MANAGERS], 'tanks header');

        $this->assertRowActions(ListTanks::class, self::ALL, [
            ['record' => Tank::first(), 'action' => 'edit', 'roles' => self::MANAGERS],
            ['record' => Tank::first(), 'action' => 'view', 'roles' => self::ALL],
        ], 'tank rows');

        // Registers: only managers open them at all, and then they get every button
        foreach ([ListVessels::class, ListCustomers::class, ListTransporters::class, ListTrucks::class, ListDrivers::class, ListBerths::class] as $page) {
            $this->assertHeaderActions($page, [], self::MANAGERS, ['create' => self::MANAGERS], class_basename($page));
        }

        // Users: admin only
        $this->assertHeaderActions(ListUsers::class, [], self::ADMIN, ['create' => self::ADMIN], 'users');
    }

    public function test_register_switches_are_for_managers_in_every_state(): void
    {
        $this->assertRowActions(ListVessels::class, self::MANAGERS, [
            ['record' => Vessel::where('name', 'MV Pacific Dawn')->first(), 'action' => 'toggle_active', 'roles' => self::MANAGERS],   // active
            ['record' => Vessel::where('name', 'MV Retired')->first(), 'action' => 'toggle_active', 'roles' => self::MANAGERS],        // inactive: offers Activate
        ], 'vessel switches');
        $this->assertRowActions(ListTrucks::class, self::MANAGERS, [
            ['record' => Truck::where('plate', 'KOLD 1X')->first(), 'action' => 'toggle_active', 'roles' => self::MANAGERS],
        ], 'truck switches');
    }

    // ---- pressing a hidden button anyway --------------------------------------------------------------------------------

    /** What would change if a forbidden action worked. */
    private function snapshot(): array
    {
        return [
            'movements' => CargoMovement::count(),
            'voyages' => VesselDischarge::pluck('status', 'id')->map(fn ($s) => $s->value)->all(),
            'berths' => VesselDischarge::pluck('berth_id', 'id')->all(),
            'orders' => DeliveryOrder::pluck('status', 'id')->map(fn ($s) => $s->value)->all(),
            'visits' => TruckVisit::pluck('status', 'id')->map(fn ($s) => $s->value)->all(),
            'corrections' => CorrectionRequest::pluck('status', 'id')->map(fn ($s) => $s->value)->all(),
            'tanks' => Tank::pluck('current_volume_kg', 'id')->all(),
            'vessels' => Vessel::pluck('active', 'id')->all(),
            'trucks' => Truck::pluck('active', 'id')->all(),
        ];
    }

    /**
     * Run $attempt as $role. It may be refused in any way (a hidden button, an unauthorised action, a 403),
     * but it must never change the data.
     */
    private function assertNothingChanges(Role $role, callable $attempt, string $what): void
    {
        $this->loginAs($role);
        $before = $this->snapshot();

        try {
            $attempt();
        } catch (AssertionFailedError|\Throwable) {
            // refused: that is the point
        }

        $this->assertSame($before, $this->snapshot(), "{$role->value} changed data by: {$what}");
    }

    public function test_pressing_a_hidden_button_by_a_crafted_request_changes_nothing(): void
    {
        $scheduled = $this->voyage('VOY-2611-05');
        $loading = $this->order('ORD-1003');
        $armed = $this->visitOf('KCD 452M');           // loading
        $arrived = $this->visitOf('KCB 887L');
        $weighedIn = $this->visitOf('KDA 999Z');
        $pending = CorrectionRequest::where('status', 'pending')->first();

        // An auditor (read-only) tries every state-changing button, without going through the test helper's own visibility check:
        // mountAction + callMountedAction is exactly what a hand-crafted Livewire request would send.
        $crafted = fn (string $page, array $params, string $action) => fn () => Livewire::test($page, $params)->call('mountAction', $action)->call('callMountedAction');

        $attempts = [
            'cancel a scheduled voyage' => $crafted(ViewVesselDischarge::class, ['record' => $scheduled->id], 'to_cancelled'),
            'complete the discharging voyage' => $crafted(ViewVesselDischarge::class, ['record' => $this->voyage('VOY-2611-03')->id], 'to_completed'),
            'complete the loading order' => $crafted(ViewDeliveryOrder::class, ['record' => $loading->id], 'to_completed'),
            'cancel the loading order' => $crafted(ViewDeliveryOrder::class, ['record' => $loading->id], 'to_cancelled'),
            'weigh out a loading truck' => $crafted(ViewTruckVisit::class, ['record' => $armed->id], 'weigh_out'),
            'start loading a weighed-in truck' => $crafted(ViewTruckVisit::class, ['record' => $weighedIn->id], 'start_loading'),
            'cancel an arrived truck' => $crafted(ViewTruckVisit::class, ['record' => $arrived->id], 'cancel_visit'),
        ];

        foreach ($attempts as $what => $attempt) {
            $this->assertNothingChanges(Role::Auditor, $attempt, $what);
        }

        // An operator tries a manager's job: deciding a correction (they cannot even open the queue).
        $this->assertNothingChanges(Role::Operator, fn () => Livewire::test(ListCorrectionRequests::class)
            ->call('mountTableAction', 'approve', $pending->id)->call('callMountedTableAction'), 'approve a correction');
        $this->assertNothingChanges(Role::Operator, fn () => Livewire::test(ListVessels::class)
            ->call('mountTableAction', 'toggle_active', Vessel::first()->id)->call('callMountedTableAction'), 'switch a vessel off');
        $this->assertNothingChanges(Role::Auditor, fn () => Livewire::test(ListTanks::class)
            ->call('mountTableAction', 'edit', Tank::first()->id), 'edit a tank');
    }

    public function test_calling_a_form_action_without_the_right_is_refused_and_books_nothing(): void
    {
        $voyage = $this->voyage('VOY-2611-03');                          // discharging, so 'pump' exists for those allowed
        $tank = Tank::where('code', 'PFAD-01')->first();
        $order = $this->order('ORD-1003');
        $loadingVisit = $this->visitOf('KCD 452M');

        $pump = fn () => Livewire::test(ViewVesselDischarge::class, ['record' => $voyage->id])->callAction('pump', data: [
            'tank_id' => $tank->id, 'quantity_kg' => 1_000, 'occurred_at' => now()->toDateTimeString(), 'idempotency_key' => 'crafted-pump']);
        $load = fn () => Livewire::test(ViewDeliveryOrder::class, ['record' => $order->id])->callAction('load', data: [
            'visit_id' => $loadingVisit->id, 'tank_id' => Tank::where('code', 'CPOL-01')->value('id'), 'quantity_kg' => 1_000,
            'occurred_at' => now()->toDateTimeString(), 'idempotency_key' => 'crafted-load']);
        $visitLoad = fn () => Livewire::test(ViewTruckVisit::class, ['record' => $loadingVisit->id])->callAction('load_visit', data: [
            'tank_id' => Tank::where('code', 'CPOL-01')->value('id'), 'quantity_kg' => 1_000, 'occurred_at' => now()->toDateTimeString(), 'idempotency_key' => 'crafted-visit-load']);
        $gateIn = fn () => Livewire::test(ListTruckVisits::class)->callAction('gate_in', data: [
            'order_id' => $order->id, 'truck_id' => Truck::where('plate', 'KDA 214X')->value('id')]);
        $movement = CargoMovement::where('idempotency_key', 'seed-1')->first();
        $correct = fn () => Livewire::test(ListCargoMovements::class)->callAction(TestAction::make('request_correction')->table($movement), data: ['reason' => 'Crafted request to be refused.']);

        foreach ([$pump, $load, $visitLoad, $gateIn, $correct] as $i => $attempt) {
            $this->assertNothingChanges(Role::Auditor, $attempt, "form action #{$i}");
        }

        $this->assertSame(0, CargoMovement::whereIn('idempotency_key', ['crafted-pump', 'crafted-load', 'crafted-visit-load'])->count());

        // and the same attempts DO work for an operator, proving the refusal above was about the role and not a broken test
        $this->loginAs(Role::Operator);
        $before = CargoMovement::count();
        $pump();
        $this->assertSame($before + 1, CargoMovement::count(), 'the same pump works for an operator');
    }
}
