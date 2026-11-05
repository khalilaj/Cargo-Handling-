<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Filament\Resources\DeliveryOrderResource\Pages\ViewDeliveryOrder;
use App\Filament\Resources\VesselDischargeResource\Pages\ViewVesselDischarge;
use App\Models\CargoMovement;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\User;
use App\Models\VesselDischarge;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function as(Role $role): User
    {
        $this->flushSession();                    // a fresh login, as in real life
        $this->app['auth']->forgetGuards();

        $u = User::where('role', $role->value)->firstOrFail();
        $this->actingAs($u);

        return $u;
    }

    public function test_capability_matrix(): void
    {
        $expect = [
            //                   operate approve master audit  users
            'admin' => [true,  true,  true,  true,  true],
            'supervisor' => [true,  true,  true,  true,  false],
            'operator' => [true,  false, false, false, false],
            'auditor' => [false, false, false, true,  false],
        ];

        foreach (Role::cases() as $r) {
            $this->assertSame($expect[$r->value], [
                $r->canOperate(), $r->canApprove(), $r->canManageMasterData(), $r->canViewAudit(), $r->canManageUsers(),
            ], "capabilities of {$r->value}");
        }
    }

    public function test_seed_creates_one_user_per_role(): void
    {
        foreach (Role::cases() as $r) {
            $this->assertSame(1, User::where('role', $r->value)->count(), $r->value);
        }
    }

    public function test_only_admin_can_open_user_management(): void
    {
        foreach ([Role::Supervisor, Role::Operator, Role::Auditor] as $r) {
            $this->as($r);
            $this->get('/admin/users')->assertForbidden();
        }

        $this->as(Role::Admin);
        $this->get('/admin/users')->assertOk();
        $this->get('/admin/users/create')->assertOk();
    }

    public function test_master_data_screens_are_for_supervisors_and_admins(): void
    {
        foreach ([Role::Operator, Role::Auditor] as $r) {
            $this->as($r);
            $this->get('/admin/tanks/create')->assertForbidden();
            $this->get('/admin/vessel-discharges/create')->assertForbidden();
            $this->get('/admin/delivery-orders/create')->assertForbidden();
            $this->get('/admin/tanks')->assertOk();                       // but they can look
        }

        $this->as(Role::Supervisor);
        $this->get('/admin/tanks/create')->assertOk();
        $this->get('/admin/vessel-discharges/create')->assertOk();
    }

    public function test_auditor_sees_no_operational_buttons_and_cannot_call_them(): void
    {
        $this->as(Role::Auditor);
        $v = VesselDischarge::where('status', DischargeStatus::Discharging->value)->first();
        $o = DeliveryOrder::where('status', OrderStatus::Loading->value)->first();

        Livewire::test(ViewVesselDischarge::class, ['record' => $v->id])
            ->assertActionHidden('pump')->assertActionHidden('to_completed');
        Livewire::test(ViewDeliveryOrder::class, ['record' => $o->id])
            ->assertActionHidden('load')->assertActionHidden('to_completed');

        // Calling it anyway is refused, and nothing is written.
        $before = CargoMovement::count();
        $refused = false;
        try {
            Livewire::test(ViewVesselDischarge::class, ['record' => $v->id])
                ->callAction('pump', data: [
                    'tank_id' => Tank::where('code', 'PFAD-01')->value('id'), 'quantity_kg' => 1_000,
                    'occurred_at' => now()->toDateTimeString(), 'idempotency_key' => 'sneaky',
                ]);
        } catch (AssertionFailedError) {
            $refused = true;                     // Filament refuses to run an unauthorised action
        }
        $this->assertTrue($refused, 'an auditor must not be able to run the pump action');
        $this->assertSame($before, CargoMovement::count());
    }

    public function test_operator_can_operate(): void
    {
        $this->as(Role::Operator);
        $v = VesselDischarge::where('status', DischargeStatus::Discharging->value)->first();

        Livewire::test(ViewVesselDischarge::class, ['record' => $v->id])
            ->assertActionVisible('pump')
            ->callAction('pump', data: [
                'tank_id' => Tank::where('code', 'PFAD-01')->value('id'), 'quantity_kg' => 10_000,
                'occurred_at' => now()->toDateTimeString(), 'idempotency_key' => 'op-1',
            ])
            ->assertNotified('Cargo pumped into tank');
    }

    public function test_nobody_can_delete_operational_data(): void
    {
        foreach (Role::cases() as $r) {
            $u = User::where('role', $r->value)->first();
            $this->assertFalse($u->can('delete', Tank::first()), "{$r->value} delete tank");
            $this->assertFalse($u->can('delete', CargoMovement::first()), "{$r->value} delete movement");
            $this->assertFalse($u->can('update', CargoMovement::first()), "{$r->value} edit movement");
        }
    }
}
