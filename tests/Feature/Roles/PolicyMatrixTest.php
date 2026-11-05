<?php

namespace Tests\Feature\Roles;

use App\Domain\Access\Role;
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
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDischarge;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/**
 * Every model x every ability x every role, written out. Laravel allows an action when a model has NO policy,
 * so the completeness tests also fail if a model or Filament resource ever ships without one.
 */
class PolicyMatrixTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    private const ALL = ['admin', 'supervisor', 'operator', 'auditor'];

    private const OPERATE = ['admin', 'supervisor', 'operator'];

    private const MANAGERS = ['admin', 'supervisor'];

    private const OVERSEERS = ['admin', 'supervisor', 'auditor'];

    private const ADMIN = ['admin'];

    private const NOBODY = [];

    /**
     * model => [ability => roles allowed]. The abilities cover everything Filament asks.
     */
    private function matrix(): array
    {
        $master = ['viewAny' => self::ALL, 'view' => self::ALL, 'create' => self::MANAGERS, 'update' => self::MANAGERS, 'delete' => self::NOBODY, 'deleteAny' => self::NOBODY];
        $register = ['viewAny' => self::MANAGERS, 'view' => self::MANAGERS, 'create' => self::MANAGERS, 'update' => self::MANAGERS, 'delete' => self::NOBODY, 'deleteAny' => self::NOBODY];
        $readOnly = fn (array $see) => ['viewAny' => $see, 'view' => $see, 'create' => self::NOBODY, 'update' => self::NOBODY, 'delete' => self::NOBODY, 'deleteAny' => self::NOBODY];

        return [
            Tank::class => $master,
            VesselDischarge::class => $master,
            DeliveryOrder::class => $master,
            CargoMovement::class => $readOnly(self::ALL),                    // an immutable ledger: nobody edits it
            TruckVisit::class => ['viewAny' => self::ALL, 'view' => self::ALL, 'create' => self::OPERATE, 'update' => self::NOBODY, 'delete' => self::NOBODY, 'deleteAny' => self::NOBODY],
            CorrectionRequest::class => $readOnly(self::OVERSEERS),
            AuditLog::class => $readOnly(self::OVERSEERS),                   // append-only
            User::class => ['viewAny' => self::ADMIN, 'view' => self::ADMIN, 'create' => self::ADMIN, 'update' => self::ADMIN, 'delete' => self::NOBODY, 'deleteAny' => self::NOBODY],
            Vessel::class => $register,
            Berth::class => $register,
            Customer::class => $register,
            Transporter::class => $register,
            Truck::class => $register,
            Driver::class => $register,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWithEdgeStates();
    }

    public function test_every_model_ability_and_role_is_decided_exactly_as_written(): void
    {
        $failures = [];
        $checked = 0;

        foreach ($this->matrix() as $model => $abilities) {
            $instance = $model::first();
            $this->assertNotNull($instance, "the scenario must contain a {$model}");

            foreach ($this->roles() as $role) {
                $user = $this->loginAs($role);

                foreach ($abilities as $ability => $allowed) {
                    $argument = in_array($ability, ['viewAny', 'create', 'deleteAny'], true) ? $model : $instance;
                    $expected = in_array($role->value, $allowed, true);
                    $checked++;

                    if ($user->can($ability, $argument) !== $expected) {
                        $failures[] = class_basename($model).".{$ability} for {$role->value}: expected ".($expected ? 'ALLOW' : 'DENY');
                    }
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
        $this->assertSame(14 * 4 * 6, $checked);
    }

    public function test_no_model_exists_without_a_decision_and_a_policy(): void
    {
        $models = collect(glob(app_path('Models/*.php')))
            ->map(fn ($f) => 'App\\Models\\'.basename($f, '.php'))
            ->filter(fn ($c) => is_subclass_of($c, Model::class))
            ->sort()->values()->all();

        $this->assertSame(collect(array_keys($this->matrix()))->sort()->values()->all(), $models,
            'A model was added or removed without updating the policy matrix.');

        foreach ($models as $model) {
            $this->assertNotNull(Gate::getPolicyFor($model), "{$model} has no policy: Laravel would ALLOW everything on it.");
        }
    }

    public function test_every_filament_resource_is_guarded_by_a_policy(): void
    {
        $resources = Filament::getPanel('admin')->getResources();
        $this->assertGreaterThanOrEqual(14, count($resources));

        foreach ($resources as $resource) {
            $model = $resource::getModel();
            $this->assertNotNull(Gate::getPolicyFor($model), "{$resource} manages {$model}, which has no policy");
            $this->assertArrayHasKey($model, $this->matrix(), "{$resource} manages {$model}, which is not in the policy matrix");
        }
    }

    public function test_nobody_can_delete_anything_ever(): void
    {
        foreach ($this->matrix() as $model => $abilities) {
            $this->assertSame([], $abilities['delete'], "{$model}: delete must stay forbidden");
            $this->assertSame([], $abilities['deleteAny'], "{$model}: bulk delete must stay forbidden");
        }
    }

    public function test_the_ledger_and_the_audit_log_cannot_be_written_by_any_role(): void
    {
        foreach ($this->roles() as $role) {
            $user = $this->loginAs($role);

            foreach ([CargoMovement::class, AuditLog::class] as $model) {
                $this->assertFalse($user->can('create', $model), "{$role->value} create {$model}");
                $this->assertFalse($user->can('update', $model::first()), "{$role->value} update {$model}");
            }
        }
    }

    public function test_the_role_helpers_agree_with_the_policies(): void
    {
        // One definition of "who may see what" feeds the menu, widgets, bell AND the policies. Prove they cannot drift.
        foreach ($this->roles() as $role) {
            $user = $this->loginAs($role);

            $this->assertSame($role->canViewRegisters(), $user->can('viewAny', Vessel::class), "{$role->value} registers");
            $this->assertSame($role->canViewCorrections(), $user->can('viewAny', CorrectionRequest::class), "{$role->value} corrections");
            $this->assertSame($role->canViewAudit(), $user->can('viewAny', AuditLog::class), "{$role->value} audit");
            $this->assertSame($role->canManageUsers(), $user->can('viewAny', User::class), "{$role->value} users");
            $this->assertSame($role->canManageMasterData(), $user->can('create', Tank::class), "{$role->value} master data");
            $this->assertSame($role->canOperate(), $user->can('create', TruckVisit::class), "{$role->value} operate");
        }
    }

    public function test_the_full_role_capability_table(): void
    {
        $expected = [
            //              operate approve master audit  users  registers reports corrections analytics alerts
            'admin' => [true,  true,  true,  true,  true,  true,  true,  true,  true,  true],
            'supervisor' => [true,  true,  true,  true,  false, true,  true,  true,  true,  true],
            'operator' => [true,  false, false, false, false, false, false, false, false, true],
            'auditor' => [false, false, false, true,  false, false, true,  true,  true,  false],
        ];

        foreach (Role::cases() as $r) {
            $this->assertSame($expected[$r->value], [
                $r->canOperate(), $r->canApprove(), $r->canManageMasterData(), $r->canViewAudit(), $r->canManageUsers(),
                $r->canViewRegisters(), $r->canViewReports(), $r->canViewCorrections(), $r->canViewAnalytics(), $r->canSeeAlerts(),
            ], "capabilities of {$r->value}");
        }
    }
}
