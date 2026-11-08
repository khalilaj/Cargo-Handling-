<?php

namespace Tests\Feature\Roles;

use App\Domain\Access\Role;
use App\Domain\Operations\Services\OperationalAlerts;
use App\Filament\Widgets\InventoryTrend;
use App\Filament\Widgets\RecentMovements;
use App\Filament\Widgets\StockOverview;
use App\Filament\Widgets\TankGauges;
use App\Filament\Widgets\TrucksOnSite;
use App\Filament\Widgets\VesselsInPort;
use App\Filament\Widgets\WorkflowGuide;
use App\Livewire\AlertsBell;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/** Each dashboard board and the top-bar bell show the right things to the right role, in every data state. */
class WidgetsAndBellTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    /** widget => roles that may see it */
    private function matrix(): array
    {
        $all = ['admin', 'supervisor', 'operator', 'auditor'];
        $overseers = ['admin', 'supervisor', 'auditor'];
        $doers = ['admin', 'supervisor', 'operator'];

        return [
            StockOverview::class => $all,
            TankGauges::class => $all,
            VesselsInPort::class => $all,
            TrucksOnSite::class => $all,
            InventoryTrend::class => $overseers,
            RecentMovements::class => $overseers,
            WorkflowGuide::class => $doers,   // auditors only read, so they get no how-to-work guide
        ];
    }

    public function test_every_registered_widget_is_in_the_matrix(): void
    {
        $this->seedUsersOnly();
        $registered = collect(Filament::getPanel('admin')->getWidgets())
            ->reject(fn ($w) => str_starts_with((string) $w, 'Filament\\'))
            ->values()->all();

        foreach ($registered as $widget) {
            $this->assertArrayHasKey($widget, $this->matrix(), "$widget has no visibility rule in the test");
        }
        foreach (array_keys($this->matrix()) as $widget) {
            $this->assertContains($widget, $registered, "$widget is in the test but not on the panel");
        }
    }

    public function test_widget_visibility_per_role(): void
    {
        $this->seedWithEdgeStates();
        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            foreach ($this->matrix() as $widget => $roles) {
                $this->assertSame(in_array($role->value, $roles, true), $widget::canView(), "{$role->value} / $widget");
            }
        }
    }

    public function test_dashboard_shows_only_permitted_widgets_and_each_mounts(): void
    {
        $this->seedWithEdgeStates();
        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            $this->get('/admin')->assertOk();
            foreach ($this->matrix() as $widget => $roles) {
                if (in_array($role->value, $roles, true)) {
                    Livewire::test($widget)->assertOk();
                }
            }
        }
    }

    public function test_widgets_render_on_an_empty_terminal(): void
    {
        $this->seedUsersOnly();
        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            $this->get('/admin')->assertOk();
            foreach ($this->matrix() as $widget => $roles) {
                if (in_array($role->value, $roles, true)) {
                    Livewire::test($widget)->assertOk();
                }
            }
        }
    }

    public function test_bell_is_hidden_from_auditors_and_present_for_the_others(): void
    {
        $this->seedWithEdgeStates();
        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            $c = Livewire::test(AlertsBell::class);
            $role->canSeeAlerts()
                ? $c->assertSee('Needs attention')
                : $c->assertDontSee('Needs attention');
        }
    }

    public function test_bell_count_matches_alerts_capped_at_twelve_and_ranked(): void
    {
        $this->seedWithEdgeStates();
        foreach ([Role::Admin, Role::Supervisor, Role::Operator] as $role) {
            $alerts = app(OperationalAlerts::class)->forRole($role);
            $this->assertLessThanOrEqual(12, $alerts->count());
            $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
            $ranks = $alerts->map(fn ($a) => $order[$a['level']])->all();
            $sorted = $ranks;
            sort($sorted);
            $this->assertSame($sorted, $ranks, "{$role->value}: danger first");
            foreach ($alerts as $a) {
                $this->assertArrayHasKey('type', $a['target']);
            }
        }
        $this->assertTrue(app(OperationalAlerts::class)->forRole(Role::Auditor)->isEmpty());
    }

    public function test_bell_shows_what_each_role_can_act_on(): void
    {
        $this->seedWithEdgeStates();
        $op = app(OperationalAlerts::class)->forRole(Role::Operator, 100)->pluck('title')->all();
        $sup = app(OperationalAlerts::class)->forRole(Role::Supervisor, 100)->pluck('title')->all();

        // the full tank appears for everyone who acts on alerts
        $this->assertTrue(collect($op)->contains(fn ($t) => str_contains($t, 'FULL-1')));
        $this->assertTrue(collect($sup)->contains(fn ($t) => str_contains($t, 'FULL-1')));
        // approvals are not an operator's business
        $this->assertFalse(collect($op)->contains(fn ($t) => stripos($t, 'correction') !== false));
        // each alert's link opens for the role that sees it
        foreach ([Role::Operator, Role::Supervisor, Role::Admin] as $role) {
            $this->loginAs($role);
            $c = Livewire::test(AlertsBell::class);
            $c->assertOk();
        }
    }

    public function test_bell_says_all_clear_when_nothing_is_wrong(): void
    {
        $this->seedUsersOnly();
        foreach ([Role::Admin, Role::Supervisor, Role::Operator] as $role) {
            $this->loginAs($role);
            Livewire::test(AlertsBell::class)->assertSee('All clear');
        }
    }
}
