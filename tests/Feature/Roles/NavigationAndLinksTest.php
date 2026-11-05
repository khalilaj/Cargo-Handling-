<?php

namespace Tests\Feature\Roles;

use App\Domain\Access\Role;
use App\Livewire\AlertsBell;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/**
 * The menu is exact for every role, and NO link a role is shown leads to a refused or broken page
 * (menu, pages, tables, relation managers, dashboard boards and the bell are all followed).
 */
class NavigationAndLinksTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWithEdgeStates();
    }

    /** group => labels in order ('' = the ungrouped Dashboard) */
    private function expectedMenu(): array
    {
        $ops = ['Vessel discharges', 'Delivery orders', 'Truck visits'];
        $stock = ['Storage tanks', 'Cargo movements'];
        $registers = ['Vessels', 'Berths', 'Customers', 'Transporters', 'Trucks', 'Drivers'];

        return [
            'operator' => ['' => ['Dashboard'], 'Operations' => $ops, 'Stock' => $stock],
            'auditor' => ['' => ['Dashboard'], 'Operations' => [...$ops, 'Corrections'], 'Stock' => $stock, 'Reports' => ['Terminal reports'], 'Administration' => ['Audit log']],
            'supervisor' => ['' => ['Dashboard'], 'Operations' => [...$ops, 'Corrections'], 'Stock' => $stock, 'Reports' => ['Terminal reports'], 'Registers' => $registers, 'Administration' => ['Audit log']],
            'admin' => ['' => ['Dashboard'], 'Operations' => [...$ops, 'Corrections'], 'Stock' => $stock, 'Reports' => ['Terminal reports'], 'Registers' => $registers, 'Administration' => ['Users & roles', 'Audit log']],
        ];
    }

    /** @return array<string, list<array{label: string, url: string, icon: mixed}>> */
    private function menuOf(): array
    {
        $out = [];
        foreach (Filament::getPanel('admin')->getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                $out[(string) $group->getLabel()][] = ['label' => (string) $item->getLabel(), 'url' => $item->getUrl(), 'icon' => $item->getIcon()];
            }
        }

        return $out;
    }

    private function path(?string $url): ?string
    {
        if ($url === null || $url === '' || str_starts_with($url, '#')) {
            return null;
        }
        $parts = parse_url(html_entity_decode($url));
        $path = rtrim($parts['path'] ?? '', '/') ?: '/';

        if ($path === '/admin/logout' || $path === '/livewire' || ! (str_starts_with($path, '/admin') || $path === '/reports/pdf')) {
            return null;
        }

        return $path.($path === '/reports/pdf' && isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** @return list<string> */
    private function linksIn(string $html): array
    {
        preg_match_all('/href="([^"]+)"/', $html, $m);

        return array_values(array_unique(array_filter(array_map(fn ($h) => $this->path($h), $m[1]))));
    }

    // ---- the menu -----------------------------------------------------------------------------------------

    public function test_the_menu_is_exactly_right_for_every_role(): void
    {
        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            $actual = collect($this->menuOf())->map(fn ($items) => array_column($items, 'label'))->all();

            $this->assertSame($this->expectedMenu()[$role->value], $actual, "menu of {$role->value}");
        }
    }

    public function test_the_menu_sizes_are_what_we_promise(): void
    {
        $sizes = ['operator' => 6, 'auditor' => 9, 'supervisor' => 15, 'admin' => 16];

        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            $this->assertSame($sizes[$role->value], collect($this->menuOf())->flatten(1)->count(), "{$role->value} menu size");
        }
    }

    public function test_every_menu_item_opens_and_every_openable_index_is_in_the_menu(): void
    {
        $indexes = [
            'filament.admin.resources.vessel-discharges.index', 'filament.admin.resources.delivery-orders.index', 'filament.admin.resources.truck-visits.index',
            'filament.admin.resources.correction-requests.index', 'filament.admin.resources.tanks.index', 'filament.admin.resources.cargo-movements.index',
            'filament.admin.pages.reports', 'filament.admin.resources.audit-logs.index', 'filament.admin.resources.users.index',
            'filament.admin.resources.vessels.index', 'filament.admin.resources.berths.index', 'filament.admin.resources.customers.index',
            'filament.admin.resources.transporters.index', 'filament.admin.resources.trucks.index', 'filament.admin.resources.drivers.index',
            'filament.admin.pages.dashboard',
        ];

        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            $menuPaths = collect($this->menuOf())->flatten(1)->map(fn ($i) => $this->path($i['url']))->all();

            // 1. nothing in the menu is a dead end
            foreach ($menuPaths as $p) {
                $this->get($p)->assertOk();
            }

            // 2. no duplicates, every item has an icon
            $this->assertSame($menuPaths, array_values(array_unique($menuPaths)), "{$role->value}: duplicate menu entries");
            foreach (collect($this->menuOf())->flatten(1) as $item) {
                $this->assertNotNull($item['icon'], "{$role->value}: '{$item['label']}' has no icon");
            }

            // 3. every index screen this role CAN open is reachable from the menu (no hidden-but-open screens)
            $openable = [];
            foreach ($indexes as $name) {
                $path = $this->path(route($name, absolute: false));
                if ($this->get($path)->getStatusCode() === 200) {
                    $openable[] = $path;
                }
            }
            sort($openable);
            $inMenu = $menuPaths;
            sort($inMenu);
            $this->assertSame($openable, $inMenu, "{$role->value}: the menu and the openable screens must be the same set");
        }
    }

    public function test_the_menu_groups_come_in_a_fixed_order(): void
    {
        $this->loginAs(Role::Admin);

        $this->assertSame(['', 'Operations', 'Stock', 'Reports', 'Registers', 'Administration'], array_keys($this->menuOf()));
    }

    // ---- follow every link ------------------------------------------------------------------------------------------

    /** Links visible in the full page, in the bell, and in every table / relation manager / widget this role can open. */
    private function everyLinkShownTo(Role $role): array
    {
        $found = [];
        $add = function (string $where, string $html) use (&$found) {
            foreach ($this->linksIn($html) as $link) {
                $found[$link] ??= $where;
            }
        };

        $add('dashboard', $this->get('/admin')->getContent());

        // dashboard boards load lazily, so mount each one the role can see
        foreach (Filament::getPanel('admin')->getWidgets() as $widget) {
            if ($widget::canView()) {
                $add('widget '.class_basename($widget), Livewire::test($widget)->html());
            }
        }
        $add('bell', Livewire::test(AlertsBell::class)->html());

        // every screen's tables and relation managers, for every record, in every status
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if (! $resource::canViewAny()) {
                continue;
            }
            $pages = $resource::getPages();
            $name = class_basename($resource);

            if (isset($pages['index'])) {
                $add("{$name} list", Livewire::test($pages['index']->getPage())->html());
            }
            if (! isset($pages['view'])) {
                continue;
            }
            foreach ($resource::getModel()::query()->limit(12)->get() as $record) {
                $viewPage = $pages['view']->getPage();
                $add("{$name} #{$record->getKey()}", Livewire::test($viewPage, ['record' => $record->getKey()])->html());

                foreach ($resource::getRelations() as $manager) {
                    $add("{$name} #{$record->getKey()} ".class_basename($manager),
                        Livewire::test($manager, ['ownerRecord' => $record, 'pageClass' => $viewPage])->html());
                }
            }
        }

        return $found;
    }

    public function test_no_link_any_role_is_shown_leads_to_a_refused_or_broken_page(): void
    {
        $summary = [];

        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            $links = $this->everyLinkShownTo($role);
            $bad = [];

            foreach ($links as $path => $shownOn) {
                $res = $this->get($path);

                if ($res->getStatusCode() !== 200) {
                    $bad[] = "{$path} -> HTTP {$res->getStatusCode()} (linked from: {$shownOn})";
                } elseif (str_starts_with($path, '/reports/pdf')) {
                    $this->assertStringContainsString('application/pdf', (string) $res->headers->get('Content-Type'), "{$path} is not a PDF");
                }
            }

            $this->assertSame([], $bad, "{$role->value} is shown links that do not work:\n".implode("\n", $bad));
            $summary[$role->value] = count($links);
        }

        // sanity: the crawl really found things to follow (a crawler that finds nothing proves nothing)
        $this->assertGreaterThan(20, $summary['operator'], 'operator links: '.json_encode($summary));
        $this->assertGreaterThan(60, $summary['admin'], 'admin links: '.json_encode($summary));
        $this->assertGreaterThan($summary['operator'], $summary['supervisor']);                   // a fuller product shows more
        $this->assertGreaterThanOrEqual($summary['supervisor'], $summary['admin']);
    }

    public function test_an_operator_is_never_shown_a_link_into_a_restricted_area(): void
    {
        $this->loginAs(Role::Operator);

        foreach (array_keys($this->everyLinkShownTo(Role::Operator)) as $path) {
            foreach (['/admin/vessels', '/admin/berths', '/admin/customers', '/admin/transporters', '/admin/trucks', '/admin/drivers',
                '/admin/users', '/admin/audit-logs', '/admin/reports', '/admin/correction-requests', '/reports/pdf'] as $restricted) {
                $this->assertFalse(str_starts_with($path, $restricted), "operator is shown a link to {$path}");
            }
        }
    }

    public function test_an_auditor_is_shown_no_link_that_changes_anything(): void
    {
        $this->loginAs(Role::Auditor);

        foreach (array_keys($this->everyLinkShownTo(Role::Auditor)) as $path) {
            $this->assertDoesNotMatchRegularExpression('#/(create|edit)$#', $path, "auditor is shown a write link {$path}");
        }
    }
}
