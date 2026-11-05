<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Cargo\Exceptions\InsufficientStockException;
use App\Domain\Cargo\Exceptions\MovementNotReversibleException;
use App\Domain\Cargo\Services\TankLedger;
use App\Domain\Corrections\Actions\ApproveCorrection;
use App\Domain\Corrections\Actions\RejectCorrection;
use App\Domain\Corrections\Actions\RequestCorrection;
use App\Domain\Corrections\Enums\CorrectionStatus;
use App\Domain\Corrections\Exceptions\CorrectionAlreadyDecidedException;
use App\Domain\Corrections\Exceptions\InvalidCorrectionException;
use App\Domain\Corrections\Exceptions\NotAuthorizedException;
use App\Domain\Corrections\Exceptions\SelfApprovalException;
use App\Filament\Resources\CargoMovementResource\Pages\ListCargoMovements;
use App\Filament\Resources\CorrectionRequestResource\Pages\ListCorrectionRequests;
use App\Filament\Widgets\AlertsPanel;
use App\Filament\Widgets\StockOverview;
use App\Models\AuditLog;
use App\Models\CargoMovement;
use App\Models\CorrectionRequest;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\User;
use App\Models\VesselDischarge;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Wrong tank was selected for this load.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        CorrectionRequest::query()->delete();      // the demo seed ships one open request; these tests start clean
    }

    private function user(Role $r): User
    {
        return User::where('role', $r->value)->firstOrFail();
    }

    private function login(Role $r): User
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($u = $this->user($r));

        return $u;
    }

    private function move(string $key): CargoMovement
    {
        return CargoMovement::where('idempotency_key', $key)->firstOrFail();
    }

    private function request(string $key, ?User $by = null): CorrectionRequest
    {
        return app(RequestCorrection::class)($this->move($key), $by ?? $this->user(Role::Operator), self::REASON);
    }

    private function approve(CorrectionRequest $r, ?User $by = null): CorrectionRequest
    {
        return app(ApproveCorrection::class)($r, $by ?? $this->user(Role::Supervisor), 'checked against the weighbridge ticket');
    }

    private function signedLoss(): int
    {
        return (int) CargoMovement::sum(DB::raw('CASE WHEN reverses_movement_id IS NULL THEN loss_kg ELSE -loss_kg END'));
    }

    // ---- the happy paths -------------------------------------------------------

    public function test_reversing_a_delivery_gives_the_cargo_back_to_the_tank_and_the_order(): void
    {
        $tank = Tank::where('code', 'CPOL-01')->first();
        $order = DeliveryOrder::where('order_ref', 'ORD-1003')->first();      // 150,000 of 400,000 loaded by seed-6
        $level = $tank->current_volume_kg;
        $count = CargoMovement::count();

        $r = $this->approve($this->request('seed-6'));

        $this->assertSame(CorrectionStatus::Approved, $r->status);
        $this->assertSame($level + 150_000, $tank->fresh()->current_volume_kg);
        $this->assertSame(0, DeliveryOrder::withTotals()->find($order->id)->deliveredKg());
        $this->assertSame(400_000, DeliveryOrder::withTotals()->find($order->id)->remainingKg());

        $this->assertSame($count + 1, CargoMovement::count());                // ledger only grows
        $rev = $r->reversal;
        $this->assertSame($this->move('seed-6')->id, $rev->reverses_movement_id);
        $this->assertSame('vessel_discharge', $rev->type->value);              // opposite direction
        $this->assertSame(0, $rev->loss_kg);                                   // no fresh 0.5% loss on a reversal
        $this->assertSame(150_000, $this->move('seed-6')->net_kg);             // original untouched
    }

    public function test_reversing_a_discharge_restores_the_bill_of_lading_and_the_loss_figure(): void
    {
        $this->assertSame(42_000, $this->signedLoss());
        $v = VesselDischarge::where('voyage_ref', 'VOY-2611-03')->first();     // 1,700,000 gross received (8,500 loss)

        $this->approve($this->request('seed-3'));

        $v = VesselDischarge::withTotals()->find($v->id);
        $this->assertSame(0, $v->receivedGrossKg());
        $this->assertSame(2_000_000, $v->remainingKg());                       // can be pumped again
        $this->assertSame(0, $v->transitLossKg());
        $this->assertSame(0, Tank::where('code', 'PFAD-01')->value('current_volume_kg'));
        $this->assertSame(33_500, $this->signedLoss());                        // 42,000 - 8,500
    }

    public function test_a_reversal_is_dated_after_the_movement_it_cancels(): void
    {
        $m = $this->move('seed-6');
        $m->update(['occurred_at' => now()->addDays(2)]);                      // a future-dated entry

        $r = $this->approve($this->request('seed-6'));

        $this->assertTrue($r->reversal->occurred_at->gt($m->fresh()->occurred_at));
    }

    // ---- who may do what -------------------------------------------------------

    public function test_four_eyes_nobody_approves_their_own_request(): void
    {
        foreach ([Role::Supervisor, Role::Admin] as $role) {
            $me = $this->user($role);
            CorrectionRequest::query()->delete();
            $req = $this->request('seed-6', $me);

            try {
                $this->approve($req, $me);
                $this->fail("{$role->value} approved their own request");
            } catch (SelfApprovalException) {
                $this->assertSame(CorrectionStatus::Pending, $req->fresh()->status);
                $this->assertNull(CargoMovement::where('reverses_movement_id', $this->move('seed-6')->id)->first());
            }
        }
    }

    public function test_only_supervisors_and_admins_can_decide(): void
    {
        $req = $this->request('seed-6');

        foreach ([Role::Operator, Role::Auditor] as $role) {
            try {
                $this->approve($req, $this->user($role));
                $this->fail("{$role->value} approved");
            } catch (NotAuthorizedException) {
                $this->assertTrue(true);
            }

            try {
                app(RejectCorrection::class)($req, $this->user($role), 'no thanks at all');
                $this->fail("{$role->value} rejected");
            } catch (NotAuthorizedException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(CorrectionStatus::Pending, $req->fresh()->status);
    }

    public function test_an_auditor_cannot_even_request_a_correction(): void
    {
        $this->expectException(NotAuthorizedException::class);
        $this->request('seed-6', $this->user(Role::Auditor));
    }

    // ---- guard rails ------------------------------------------------------------

    public function test_a_reason_is_required(): void
    {
        $this->expectException(InvalidCorrectionException::class);
        app(RequestCorrection::class)($this->move('seed-6'), $this->user(Role::Operator), 'oops');
    }

    public function test_only_one_open_request_per_movement(): void
    {
        $this->request('seed-6');

        $this->expectException(InvalidCorrectionException::class);
        $this->request('seed-6');
    }

    public function test_a_movement_is_reversed_at_most_once_and_a_reversal_is_final(): void
    {
        $r = $this->approve($this->request('seed-6'));

        try {
            $this->request('seed-6');
            $this->fail('already reversed');
        } catch (InvalidCorrectionException $e) {
            $this->assertStringContainsString('already been reversed', $e->getMessage());
        }

        // And the ledger itself refuses a second reversal even if the workflow were bypassed.
        try {
            app(TankLedger::class)->reverse($this->move('seed-6'), now(), 'another-key');
            $this->fail();
        } catch (MovementNotReversibleException) {
            $this->assertTrue(true);
        }

        // A reversal can not be corrected, nor reversed.
        try {
            app(RequestCorrection::class)($r->reversal, $this->user(Role::Operator), self::REASON);
            $this->fail();
        } catch (InvalidCorrectionException) {
            $this->assertTrue(true);
        }
        $this->expectException(MovementNotReversibleException::class);
        app(TankLedger::class)->reverse($r->reversal, now(), 'rev-of-rev');
    }

    public function test_a_decision_cannot_be_taken_twice(): void
    {
        $req = $this->request('seed-6');
        $this->approve($req);

        $this->expectException(CorrectionAlreadyDecidedException::class);
        $this->approve($req->fresh());
    }

    public function test_approval_that_would_empty_a_tank_below_zero_fails_and_changes_nothing(): void
    {
        // seed-1 put 4,179,000 kg into CPO-01; 350,000 has since gone out. Reversing the discharge is impossible.
        $req = $this->request('seed-1');
        $count = CargoMovement::count();
        $level = Tank::where('code', 'CPO-01')->value('current_volume_kg');

        try {
            $this->approve($req);
            $this->fail('expected the tank to refuse');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('below zero', $e->getMessage());
        }

        $this->assertSame(CorrectionStatus::Pending, $req->fresh()->status);   // still waiting; supervisor can reject instead
        $this->assertSame($count, CargoMovement::count());
        $this->assertSame($level, Tank::where('code', 'CPO-01')->value('current_volume_kg'));
    }

    public function test_rejection_needs_a_note_leaves_the_ledger_alone_and_allows_a_new_request(): void
    {
        $req = $this->request('seed-6');
        $count = CargoMovement::count();

        try {
            app(RejectCorrection::class)($req, $this->user(Role::Supervisor), ' ');
            $this->fail();
        } catch (InvalidCorrectionException) {
            $this->assertTrue(true);
        }

        $req = app(RejectCorrection::class)($req, $this->user(Role::Supervisor), 'The ticket matches, no error.');
        $this->assertSame(CorrectionStatus::Rejected, $req->status);
        $this->assertSame($count, CargoMovement::count());

        $this->request('seed-6');                                              // allowed again after a rejection
        $this->assertSame(1, CorrectionRequest::where('status', 'pending')->count());
    }

    // ---- audit trail ------------------------------------------------------------

    public function test_the_whole_workflow_is_in_the_audit_log_with_the_right_people(): void
    {
        $op = $this->login(Role::Operator);
        $req = $this->request('seed-6', $op);
        $this->login(Role::Supervisor);
        $this->approve($req);

        $created = AuditLog::where('event', 'correction_request.created')->latest('id')->first();
        $this->assertSame('operator', $created->actor_role);

        $decided = AuditLog::where('event', 'correction_request.updated')->latest('id')->first();
        $this->assertSame('supervisor', $decided->actor_role);
        $this->assertSame('pending', $decided->old_values['status']);
        $this->assertSame('approved', $decided->new_values['status']);

        $reversal = AuditLog::where('event', 'cargo_movement.created')->latest('id')->first();
        $this->assertSame('supervisor', $reversal->actor_role);
        $this->assertSame('Reversal of #'.$this->move('seed-6')->id, $reversal->new_values['reference']);
    }

    // ---- the screens ------------------------------------------------------------

    public function test_the_buttons_on_the_screens(): void
    {
        $m = $this->move('seed-6');

        $this->login(Role::Auditor);
        Livewire::test(ListCargoMovements::class)->assertActionHidden(TestAction::make('request_correction')->table($m));

        $this->login(Role::Operator);
        Livewire::test(ListCargoMovements::class)
            ->callAction(TestAction::make('request_correction')->table($m), data: ['reason' => self::REASON])
            ->assertNotified('Correction requested: waiting for a supervisor');
        $req = CorrectionRequest::first();

        // the movement no longer offers another request while one is pending
        Livewire::test(ListCargoMovements::class)->assertActionHidden(TestAction::make('request_correction')->table($m->fresh()));

        // an operator cannot decide
        Livewire::test(ListCorrectionRequests::class)->assertActionHidden(TestAction::make('approve')->table($req));

        $this->login(Role::Supervisor);
        Livewire::test(AlertsPanel::class)->assertSee('1 correction request awaiting approval');
        Livewire::test(ListCorrectionRequests::class)
            ->callAction(TestAction::make('approve')->table($req), data: ['note' => 'ok'])
            ->assertNotified('Correction approved: reversal recorded');

        $this->assertTrue($m->fresh()->isReversed());
        Livewire::test(AlertsPanel::class)->assertDontSee('awaiting approval');
    }

    public function test_the_self_approval_attempt_in_the_ui_is_refused_with_a_message(): void
    {
        $sup = $this->login(Role::Supervisor);
        $req = $this->request('seed-6', $sup);

        Livewire::test(ListCorrectionRequests::class)
            ->callAction(TestAction::make('approve')->table($req), data: [])
            ->assertNotified('Not allowed');

        $this->assertSame(CorrectionStatus::Pending, $req->fresh()->status);
    }

    public function test_the_loss_figure_on_the_dashboard_follows_reversals(): void
    {
        $this->login(Role::Admin);
        Livewire::test(StockOverview::class)->assertSee('42.00 t');

        $this->approve($this->request('seed-3'));

        Livewire::test(StockOverview::class)->assertSee('33.50 t');
    }

    public function test_correction_screens_load_for_every_role(): void
    {
        $req = $this->request('seed-6');

        foreach (Role::cases() as $r) {
            $this->login($r);
            $this->get('/admin/correction-requests')->assertOk();
            $this->get("/admin/correction-requests/{$req->id}")->assertOk();
        }
    }
}
