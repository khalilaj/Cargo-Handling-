<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Filament\Resources\VesselDischargeResource\Pages\ViewVesselDischarge;
use App\Livewire\AlertsBell;
use App\Models\CorrectionRequest;
use App\Models\Tank;
use App\Models\TruckVisit;
use App\Models\User;
use App\Models\VesselDischarge;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Each role gets a simpler, smaller product: only the screens it needs, in the menu AND by direct URL,
 * the dashboard boards that matter to it, and a bell that only lists what it can act on.
 */
class RoleViewsTest extends TestCase
{
    use RefreshDatabase;

    /** Menu labels that distinguish the areas (each appears in the sidebar only). */
    private const NAV = [
        'Vessel discharges', 'Delivery orders', 'Truck visits', 'Corrections', 'Storage tanks', 'Cargo movements',
        'Terminal reports', 'Berths', 'Transporters', 'Drivers', 'Audit log', 'Users & roles',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function login(Role $role): User
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        // In production every request builds the menu afresh. Inside one test process Filament would reuse the
        // previous user's menu, so reset it when the person changes.
        $this->app->forgetInstance(NavigationManager::class);
        $this->actingAs($u = User::where('role', $role->value)->firstOrFail());

        return $u;
    }

    /** @return array<string, list<string>> role => the menu labels it should see */
    private function expectedNav(): array
    {
        $ops = ['Vessel discharges', 'Delivery orders', 'Truck visits'];
        $stock = ['Storage tanks', 'Cargo movements'];
        $registers = ['Berths', 'Transporters', 'Drivers'];

        return [
            'admin' => [...$ops, 'Corrections', ...$stock, 'Terminal reports', ...$registers, 'Audit log', 'Users & roles'],
            'supervisor' => [...$ops, 'Corrections', ...$stock, 'Terminal reports', ...$registers, 'Audit log'],
            'operator' => [...$ops, ...$stock],
            'auditor' => [...$ops, 'Corrections', ...$stock, 'Terminal reports', 'Audit log'],
        ];
    }

    public function test_the_side_menu_shows_each_role_only_its_own_areas(): void
    {
        foreach (Role::cases() as $role) {
            $this->login($role);
            $html = $this->get('/admin')->assertOk()->getContent();

            foreach (self::NAV as $label) {
                in_array($label, $this->expectedNav()[$role->value], true)
                    ? $this->assertStringContainsString(e($label), $html, "{$role->value} should see '{$label}'")
                    : $this->assertStringNotContainsString(e($label), $html, "{$role->value} must NOT see '{$label}'");
            }
        }
    }

    public function test_a_hidden_screen_is_also_refused_by_direct_url(): void
    {
        $areas = [
            'vessel-discharges' => ['admin', 'supervisor', 'operator', 'auditor'],
            'delivery-orders' => ['admin', 'supervisor', 'operator', 'auditor'],
            'truck-visits' => ['admin', 'supervisor', 'operator', 'auditor'],
            'tanks' => ['admin', 'supervisor', 'operator', 'auditor'],
            'cargo-movements' => ['admin', 'supervisor', 'operator', 'auditor'],
            'correction-requests' => ['admin', 'supervisor', 'auditor'],
            'reports' => ['admin', 'supervisor', 'auditor'],
            'audit-logs' => ['admin', 'supervisor', 'auditor'],
            'vessels' => ['admin', 'supervisor'],
            'berths' => ['admin', 'supervisor'],
            'customers' => ['admin', 'supervisor'],
            'transporters' => ['admin', 'supervisor'],
            'trucks' => ['admin', 'supervisor'],
            'drivers' => ['admin', 'supervisor'],
            'users' => ['admin'],
        ];

        foreach (Role::cases() as $role) {
            $this->login($role);
            foreach ($areas as $area => $allowed) {
                $res = $this->get("/admin/{$area}");
                in_array($role->value, $allowed, true) ? $res->assertOk() : $res->assertForbidden();
            }
        }
    }

    public function test_each_role_sees_only_the_menu_groups_it_needs(): void
    {
        $groups = fn () => (function (string $html) {
            preg_match_all('/data-group-label="([^"]+)"/', $html, $m);

            return array_values(array_filter($m[1]));
        })($this->get('/admin')->getContent());

        $this->login(Role::Operator);
        $this->assertSame(['Operations', 'Stock'], $groups(), 'an operator gets two groups');

        $this->login(Role::Auditor);
        $this->assertSame(['Operations', 'Stock', 'Reports', 'Administration'], $groups());

        $this->login(Role::Supervisor);
        $this->assertSame(['Operations', 'Stock', 'Reports', 'Registers', 'Administration'], $groups());

        $this->login(Role::Admin);
        $html = $this->get('/admin')->getContent();
        $this->assertSame(['Operations', 'Stock', 'Reports', 'Registers', 'Administration'], $groups());
        $this->assertStringNotContainsString('data-group-label="Inventory"', $html);        // the group was renamed to Stock

        // Rarely used groups start collapsed; the daily ones do not.
        $this->assertStringContainsString('\\u0022Registers\\u0022,\\u0022Administration\\u0022]', $html);
    }

    public function test_the_dashboard_is_lean_for_operators_and_fuller_for_managers(): void
    {
        $shown = fn () => collect(Filament::getPanel('admin')->getWidgets())
            ->filter(fn (string $w) => $w::canView())->map(fn ($w) => class_basename($w))->sort()->values()->all();

        $live = ['StockOverview', 'TankGauges', 'TrucksOnSite', 'VesselsInPort'];
        $analytics = ['InventoryTrend', 'RecentMovements'];

        $guide = ['WorkflowGuide'];   // the how-to-work guide, for roles that do the work

        $this->login(Role::Operator);
        $this->assertSame(collect([...$live, ...$guide])->sort()->values()->all(), $shown(), 'operators get the live boards and the guide');

        foreach ([Role::Supervisor, Role::Admin] as $role) {
            $this->login($role);
            $this->assertSame(collect([...$live, ...$analytics, ...$guide])->sort()->values()->all(), $shown(), "{$role->value} also gets the trends");
        }

        $this->login(Role::Auditor);
        $this->assertSame(collect([...$live, ...$analytics])->sort()->values()->all(), $shown(), 'auditors read only, so no guide');
    }

    public function test_needs_attention_is_no_longer_a_dashboard_widget(): void
    {
        foreach ([Role::Admin, Role::Operator] as $role) {
            $this->login($role);
            $this->assertStringNotContainsString('Tank limits, late vessels and open deliveries', $this->get('/admin')->getContent());
        }
        $this->assertFileDoesNotExist(app_path('Filament/Widgets/AlertsPanel.php'));
    }

    // ---- the bell -------------------------------------------------------------------------------------

    public function test_the_bell_is_in_the_top_bar_for_roles_that_act_on_alerts(): void
    {
        foreach ([Role::Operator, Role::Supervisor, Role::Admin] as $role) {
            $this->login($role);
            $this->assertStringContainsString('alerts-bell', $this->get('/admin')->getContent(), "{$role->value} has the bell");
        }

        $this->login(Role::Auditor);                                                     // the component is there but renders nothing
        Livewire::test(AlertsBell::class)->assertDontSee('Needs attention');
    }

    public function test_the_bell_lists_only_what_the_role_can_act_on(): void
    {
        CorrectionRequest::where('status', 'pending')->exists() || $this->fail('the demo seeds one open correction');
        Tank::where('code', 'PFAD-01')->update(['current_volume_kg' => 1_900_000]);       // 95%: a tank alert for everyone who acts

        $this->login(Role::Operator);
        Livewire::test(AlertsBell::class)
            ->assertSee('Needs attention')
            ->assertSee('PFAD-01 nearly full')
            ->assertSee('KDA 999Z on site with warnings')
            ->assertDontSee('awaiting approval')                                          // an approver's task
            ->assertDontSee('Documents to chase');                                        // a registers task

        $this->login(Role::Supervisor);
        Livewire::test(AlertsBell::class)
            ->assertSee('PFAD-01 nearly full')
            ->assertSee('1 correction request awaiting approval')
            ->assertSee('Documents to chase');
    }

    public function test_normal_progress_is_not_an_alert(): void
    {
        $this->login(Role::Supervisor);

        Livewire::test(AlertsBell::class)
            ->assertDontSee('ORD-1003 loading')                                           // an order loading is business as usual, shown on the boards
            ->assertDontSee('MV Kenya Star discharging');                                 // a ship alongside, not late
    }

    public function test_each_alert_links_to_the_right_screen(): void
    {
        Tank::where('code', 'PFAD-01')->update(['current_volume_kg' => 1_900_000]);
        $tank = Tank::where('code', 'PFAD-01')->first();
        $visit = TruckVisit::whereNotNull('active_truck_id')->whereHas('truck', fn ($q) => $q->where('plate', 'KDA 999Z'))->first();

        $this->login(Role::Supervisor);
        $html = Livewire::test(AlertsBell::class)->html();

        $this->assertStringContainsString("/admin/tanks/{$tank->id}", $html);
        $this->assertStringContainsString("/admin/truck-visits/{$visit->id}", $html);
        $this->assertStringContainsString('/admin/correction-requests', $html);
        $this->assertStringContainsString('/admin/trucks', $html);
    }

    public function test_the_bell_is_always_current_it_clears_when_the_problem_is_fixed(): void
    {
        $this->login(Role::Supervisor);
        Tank::where('code', 'PFAD-01')->update(['current_volume_kg' => 1_900_000]);
        $bell = Livewire::test(AlertsBell::class)->assertSee('PFAD-01 nearly full');

        Tank::where('code', 'PFAD-01')->update(['current_volume_kg' => 1_000_000]);
        $bell->call('$refresh')->assertDontSee('PFAD-01 nearly full');                    // what wire:poll does every 30 s
    }

    // ---- links that would lead somewhere a role cannot go --------------------------------------------

    public function test_an_operator_is_not_offered_links_to_screens_they_cannot_open(): void
    {
        $this->login(Role::Operator);
        $voyage = VesselDischarge::where('voyage_ref', 'VOY-2611-03')->first();

        $html = Livewire::test(ViewVesselDischarge::class, ['record' => $voyage->id])->assertOk()->html();
        $this->assertStringNotContainsString('/admin/vessels/', $html);                   // the vessel register is not theirs

        $this->login(Role::Supervisor);
        $html = Livewire::test(ViewVesselDischarge::class, ['record' => $voyage->id])->html();
        $this->assertStringContainsString('/admin/vessels/', $html);                      // a supervisor does get the link
    }
}
