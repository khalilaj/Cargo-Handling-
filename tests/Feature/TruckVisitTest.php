<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Corrections\Actions\ApproveCorrection;
use App\Domain\Corrections\Actions\RequestCorrection;
use App\Domain\Fleet\Actions\TruckVisitWorkflow;
use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Fleet\Exceptions\InvalidVisitException;
use App\Domain\Fleet\Exceptions\RegisterInactiveException;
use App\Domain\Fleet\Exceptions\TruckAlreadyOnSiteException;
use App\Domain\Fleet\Exceptions\TruckOverloadedException;
use App\Domain\Operations\Actions\AdvanceOrderStatus;
use App\Domain\Operations\Actions\RecordTruckLoad;
use App\Domain\Operations\Enums\OrderStatus;
use App\Domain\Operations\Exceptions\OperationIncompleteException;
use App\Models\AuditLog;
use App\Models\CargoMovement;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\TruckVisit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TruckVisitTest extends TestCase
{
    use RefreshDatabase;

    private TruckVisitWorkflow $flow;

    private Tank $tank;

    private DeliveryOrder $order;

    private Truck $truck;

    private Driver $driver;

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flow = app(TruckVisitWorkflow::class);
        $this->tank = Tank::create(['code' => 'T1', 'name' => 'T1', 'commodity' => Commodity::CPOL, 'capacity_kg' => 5_000_000,
            'opening_volume_kg' => 1_000_000, 'current_volume_kg' => 1_000_000]);
        $this->order = $this->order('ORD-1', OrderStatus::Loading);
        $this->truck = $this->truck('KDA 100A');
        $this->driver = Driver::create(['name' => 'Joseph Kamau', 'licence_number' => 'DL-1', 'licence_expiry' => now()->addYear()]);
    }

    private function order(string $ref, OrderStatus $s, int $qty = 500_000): DeliveryOrder
    {
        return DeliveryOrder::create(['order_ref' => $ref, 'customer' => 'Acme', 'commodity' => Commodity::CPOL, 'ordered_kg' => $qty, 'status' => $s]);
    }

    private function truck(string $plate, ?int $payload = 30_000, ?int $tare = 14_000): Truck
    {
        return Truck::create(['plate' => $plate, 'capacity_kg' => $payload, 'tare_kg' => $tare,
            'insurance_expiry' => now()->addYear(), 'inspection_expiry' => now()->addYear()]);
    }

    private function load(TruckVisit $v, int $kg, ?string $key = null, ?DeliveryOrder $order = null): CargoMovement
    {
        return app(RecordTruckLoad::class)($order ?? $this->order, $this->tank->id, $kg, Carbon::now(), $key ?? 'k'.++$this->keys, null, $v);
    }

    /** A visit that is weighed in and loading, ready for cargo. */
    private function loadingVisit(?Truck $truck = null): TruckVisit
    {
        $v = $this->flow->gateIn($this->order, $truck ?? $this->truck, $this->driver);
        $this->flow->weighIn($v, 14_000);

        return $this->flow->startLoading($v);
    }

    // ---- the journey ---------------------------------------------------------------------------------

    public function test_a_truck_goes_through_the_whole_visit(): void
    {
        $v = $this->flow->gateIn($this->order, $this->truck, $this->driver);
        $this->assertSame(VisitStatus::Arrived, $v->status);
        $this->assertSame($this->truck->id, $v->active_truck_id);
        $this->assertNull($v->warnings);                                           // documents are fine

        $v = $this->flow->weighIn($v, 14_000);
        $v = $this->flow->startLoading($v);
        $m = $this->load($v, 20_000);
        $v = $this->flow->weighOut($v, 34_000);                                    // scale net 20,000 = booked 20,000
        $v = $this->flow->gateOut($v);

        $this->assertSame(VisitStatus::Departed, $v->status);
        $this->assertNull($v->active_truck_id);                                    // truck is free again
        $this->assertSame(20_000, $v->loadedKg());
        $this->assertSame(0, $v->varianceKg());
        $this->assertNull($v->warnings);
        $this->assertSame($v->id, $m->truck_visit_id);
        $this->assertSame('ORD-1 · KDA 100A', $m->reference);                      // the register's plate, not typed text
        $this->assertSame(980_000, $this->tank->fresh()->current_volume_kg);
        $this->assertSame(20_000, DeliveryOrder::withTotals()->find($this->order->id)->deliveredKg());
        $this->assertSame('TV-'.str_pad((string) $v->id, 5, '0', STR_PAD_LEFT), $v->ref);

        $this->flow->gateIn($this->order, $this->truck, $this->driver);            // and it can come back
        $this->assertSame(2, TruckVisit::count());
    }

    public function test_expired_documents_warn_but_never_block(): void
    {
        $truck = Truck::create(['plate' => 'KDA 200B', 'capacity_kg' => 30_000, 'tare_kg' => 14_000,
            'insurance_expiry' => now()->subDays(3)->toDateString(), 'inspection_expiry' => null]);
        $driver = Driver::create(['name' => 'Peter Otieno', 'licence_number' => 'DL-2', 'licence_expiry' => now()->subDay()->toDateString()]);

        $v = $this->flow->gateIn($this->order, $truck, $driver);                   // admitted

        $this->assertCount(3, $v->warnings);
        $this->assertStringContainsString('insurance expired 3 days ago', $v->warnings[0]);
        $this->assertStringContainsString('inspection expiry date is not recorded', $v->warnings[1]);
        $this->assertStringContainsString('Peter Otieno licence expired 1 day ago', $v->warnings[2]);

        $this->flow->weighIn($v, 14_000);                                          // and carries on normally
        $v = $this->flow->startLoading($v);
        $this->load($v, 10_000);
        $v = $this->flow->gateOut($this->flow->weighOut($v, 24_000));
        $this->assertSame(VisitStatus::Departed, $v->status);
        $this->assertCount(3, $v->warnings);                                       // the warnings stay on the record
    }

    // ---- one truck, one visit ----------------------------------------------------------------------------

    public function test_a_truck_cannot_be_on_site_twice(): void
    {
        $first = $this->flow->gateIn($this->order, $this->truck);

        try {
            $this->flow->gateIn($this->order, $this->truck);
            $this->fail('second gate-in accepted');
        } catch (TruckAlreadyOnSiteException $e) {
            $this->assertStringContainsString($first->ref, $e->getMessage());
        }
        $this->assertSame(1, TruckVisit::count());
    }

    public function test_the_database_itself_refuses_a_second_open_visit(): void
    {
        $this->flow->gateIn($this->order, $this->truck);

        $this->expectException(QueryException::class);
        TruckVisit::create(['truck_id' => $this->truck->id, 'delivery_order_id' => $this->order->id, 'status' => VisitStatus::Arrived,
            'active_truck_id' => $this->truck->id, 'gate_in_at' => now()]);        // bypasses every application check
    }

    public function test_a_driver_cannot_be_with_two_trucks(): void
    {
        $this->flow->gateIn($this->order, $this->truck, $this->driver);

        $this->expectException(TruckAlreadyOnSiteException::class);
        $this->flow->gateIn($this->order, $this->truck('KDA 300C'), $this->driver);
    }

    public function test_inactive_trucks_and_drivers_are_refused(): void
    {
        $off = Truck::create(['plate' => 'KDA 400D', 'active' => false]);
        try {
            $this->flow->gateIn($this->order, $off);
            $this->fail();
        } catch (RegisterInactiveException) {
            $this->assertTrue(true);
        }

        $gone = Driver::create(['name' => 'Gone', 'licence_number' => 'DL-9', 'active' => false]);
        $this->expectException(RegisterInactiveException::class);
        $this->flow->gateIn($this->order, $this->truck, $gone);
    }

    // ---- orders ------------------------------------------------------------------------------------------

    public function test_only_open_orders_accept_trucks(): void
    {
        $this->assertSame(VisitStatus::Arrived, $this->flow->gateIn($this->order('ORD-P', OrderStatus::Pending), $this->truck)->status);

        foreach ([OrderStatus::Completed, OrderStatus::Cancelled] as $s) {
            try {
                $this->flow->gateIn($this->order("ORD-{$s->value}", $s), $this->truck('KDA '.strtoupper(substr($s->value, 0, 3)).' 1'));
                $this->fail("{$s->value} accepted");
            } catch (InvalidVisitException $e) {
                $this->assertStringContainsString($s->label(), $e->getMessage());
            }
        }
    }

    public function test_loading_cannot_start_until_the_order_is_loading(): void
    {
        $pending = $this->order('ORD-P', OrderStatus::Pending);
        $v = $this->flow->gateIn($pending, $this->truck);
        $this->flow->weighIn($v, 14_000);

        try {
            $this->flow->startLoading($v);
            $this->fail('loading started on a pending order');
        } catch (InvalidVisitException $e) {
            $this->assertStringContainsString('Start loading the order first', $e->getMessage());
        }

        $pending->update(['status' => OrderStatus::Loading]);
        $this->assertSame(VisitStatus::Loading, $this->flow->startLoading($v)->status);
    }

    public function test_an_order_cannot_be_closed_while_a_truck_is_on_site(): void
    {
        $v = $this->loadingVisit();
        $this->load($v, 5_000);

        foreach ([OrderStatus::Completed, OrderStatus::Cancelled] as $to) {
            try {
                app(AdvanceOrderStatus::class)($this->order->fresh(), $to);
                $this->fail("{$to->value} allowed");
            } catch (OperationIncompleteException $e) {
                $this->assertStringContainsString('KDA 100A', $e->getMessage());
            }
        }

        $this->flow->gateOut($this->flow->weighOut($v, 19_000));
        $this->assertSame(OrderStatus::Completed, app(AdvanceOrderStatus::class)($this->order->fresh(), OrderStatus::Completed, mayCloseShort: true)->status);
    }

    // ---- the state machine ----------------------------------------------------------------------------------

    public function test_steps_cannot_be_skipped(): void
    {
        $v = $this->flow->gateIn($this->order, $this->truck);

        foreach ([
            fn () => $this->flow->startLoading($v),            // not weighed in
            fn () => $this->flow->weighOut($v, 30_000),
            fn () => $this->flow->gateOut($v),
        ] as $try) {
            try {
                $try();
                $this->fail('a step was skipped');
            } catch (InvalidVisitException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(VisitStatus::Arrived, $v->fresh()->status);
    }

    public function test_cargo_only_flows_while_the_truck_is_loading(): void
    {
        $v = $this->flow->gateIn($this->order, $this->truck);

        try {
            $this->load($v, 1_000);
            $this->fail('loaded while arrived');
        } catch (InvalidVisitException $e) {
            $this->assertStringContainsString('loading bay', $e->getMessage());
        }

        $this->flow->weighIn($v, 14_000);
        $this->flow->startLoading($v);
        $this->load($v, 1_000);
        $this->flow->weighOut($v, 15_000);

        $this->expectException(InvalidVisitException::class);                       // too late: already weighed out
        $this->load($v->fresh(), 1_000);
    }

    public function test_a_visit_can_be_cancelled_before_loading_and_frees_the_truck(): void
    {
        $v = $this->flow->gateIn($this->order, $this->truck);
        $v = $this->flow->cancel($v);

        $this->assertSame(VisitStatus::Cancelled, $v->status);
        $this->assertNull($v->active_truck_id);
        $this->flow->gateIn($this->order, $this->truck);                            // truck can be gated in again
    }

    public function test_a_visit_cannot_be_cancelled_once_loading_has_started(): void
    {
        $v = $this->loadingVisit();

        $this->expectException(InvalidVisitException::class);
        $this->flow->cancel($v);
    }

    // ---- payload ---------------------------------------------------------------------------------------------

    public function test_a_truck_cannot_be_loaded_beyond_its_payload(): void
    {
        $v = $this->loadingVisit();                                                  // payload 30,000
        $this->load($v, 20_000);

        try {
            $this->load($v, 15_000);
            $this->fail('overload accepted');
        } catch (TruckOverloadedException $e) {
            $this->assertStringContainsString('room for 10,000 kg more', $e->getMessage());
        }

        $this->assertSame(20_000, $v->fresh()->loadedKg());                          // nothing was booked
        $this->assertSame(980_000, $this->tank->fresh()->current_volume_kg);

        $this->load($v, 10_000);                                                     // exactly the payload is fine
        $this->assertSame(30_000, $v->fresh()->loadedKg());
    }

    public function test_a_truck_without_a_recorded_payload_has_no_limit(): void
    {
        $v = $this->loadingVisit($this->truck('KDA 500E', payload: null));

        $this->load($v, 100_000);
        $this->assertSame(100_000, $v->fresh()->loadedKg());
    }

    // ---- loads belong to the right visit ----------------------------------------------------------------------

    public function test_a_load_cannot_use_a_visit_for_another_order(): void
    {
        $v = $this->loadingVisit();
        $other = $this->order('ORD-2', OrderStatus::Loading);

        $this->expectException(InvalidVisitException::class);
        $this->load($v, 1_000, null, $other);
    }

    public function test_retrying_a_load_with_the_same_key_counts_once(): void
    {
        $v = $this->loadingVisit();

        $a = $this->load($v, 30_000, 'same');                                         // fills the truck completely
        $b = $this->load($v, 30_000, 'same');                                         // a retry must not trip the payload check

        $this->assertSame($a->id, $b->id);
        $this->assertSame(30_000, $v->fresh()->loadedKg());
    }

    public function test_loading_without_a_visit_still_works_for_older_records(): void
    {
        $m = app(RecordTruckLoad::class)($this->order, $this->tank->id, 5_000, Carbon::now(), 'legacy', 'KXX 1Y');

        $this->assertNull($m->truck_visit_id);
        $this->assertSame('ORD-1 · KXX 1Y', $m->reference);
    }

    // ---- the weighbridge ------------------------------------------------------------------------------------------

    public function test_weighbridge_checks(): void
    {
        $v = $this->flow->gateIn($this->order, $this->truck);

        try {
            $this->flow->weighIn($v, 0);
            $this->fail();
        } catch (InvalidVisitException) {
            $this->assertTrue(true);
        }

        $v = $this->flow->weighIn($v, 16_500);                                       // registered tare 14,000: +18%
        $this->assertStringContainsString('differs from the registered 14,000 kg by 18%', $v->warnings[0]);

        $v = $this->flow->startLoading($v);
        try {
            $this->flow->weighOut($v, 40_000);
            $this->fail('weighed out with nothing booked');
        } catch (InvalidVisitException $e) {
            $this->assertStringContainsString('no cargo booked', $e->getMessage());
        }

        $this->load($v, 20_000);
        try {
            $this->flow->weighOut($v, 16_000);
            $this->fail('lighter than empty');
        } catch (InvalidVisitException $e) {
            $this->assertStringContainsString('more than the empty weight', $e->getMessage());
        }
    }

    public function test_the_scale_disagreeing_with_the_ledger_warns_but_does_not_block(): void
    {
        $v = $this->loadingVisit();
        $this->load($v, 20_000);

        $v = $this->flow->weighOut($v, 14_000 + 20_400);                              // scale says 20,400, tank says 20,000

        $this->assertSame(VisitStatus::WeighedOut, $v->status);
        $this->assertSame(400, $v->varianceKg());
        $this->assertStringContainsString('Weighbridge shows 20,400 kg but 20,000 kg was booked', collect($v->warnings)->last());
    }

    public function test_small_scale_differences_are_tolerated(): void
    {
        $v = $this->loadingVisit();
        $this->load($v, 20_000);

        $v = $this->flow->weighOut($v, 14_000 + 20_080);                              // +80 kg is inside max(100 kg, 0.5%)

        $this->assertNull($v->warnings);
        $this->assertSame(80, $v->varianceKg());
    }

    public function test_an_overweight_scale_reading_is_flagged(): void
    {
        $v = $this->loadingVisit();
        $this->load($v, 29_900);

        $v = $this->flow->weighOut($v, 14_000 + 30_600);                              // 30,600 on the scale vs 30,000 payload

        $this->assertTrue(collect($v->warnings)->contains(fn ($w) => str_contains($w, 'over the truck payload of 30,000 kg')));
    }

    // ---- corrections and audit ----------------------------------------------------------------------------------------

    public function test_a_corrected_load_updates_the_visit_total(): void
    {
        $v = $this->loadingVisit();
        $m = $this->load($v, 20_000);
        $this->load($v, 5_000);

        $sup = User::factory()->role(Role::Supervisor)->create();
        $op = User::factory()->role(Role::Operator)->create();
        $req = app(RequestCorrection::class)($m, $op, 'Wrong tank was used for this load.');
        $rev = app(ApproveCorrection::class)($req, $sup)->reversal;

        $this->assertSame($v->id, $rev->truck_visit_id);                              // reversal stays with the visit
        $this->assertSame(5_000, $v->fresh()->loadedKg());
        $this->assertSame(5_000, TruckVisit::withTotals()->find($v->id)->loadedKg());
    }

    public function test_the_visit_is_audited_with_its_warnings(): void
    {
        $this->actingAs(User::factory()->role(Role::Operator)->create(['name' => 'Gate Clerk']));
        $stale = Truck::create(['plate' => 'KDA 600F', 'insurance_expiry' => now()->subDay()->toDateString(), 'inspection_expiry' => now()->addYear()]);

        $v = $this->flow->gateIn($this->order, $stale);
        $this->flow->weighIn($v, 14_000);

        $created = AuditLog::where('event', 'truck_visit.created')->first();
        $this->assertSame('Gate Clerk', $created->actor_name);
        $this->assertStringContainsString('insurance expired', json_encode($created->new_values));

        $moved = AuditLog::where('event', 'truck_visit.updated')->latest('id')->first();
        $this->assertSame('arrived', $moved->old_values['status']);
        $this->assertSame('weighed_in', $moved->new_values['status']);
    }
}
