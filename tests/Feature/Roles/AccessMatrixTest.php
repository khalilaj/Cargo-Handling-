<?php

namespace Tests\Feature\Roles;

use App\Domain\Access\Role;
use App\Models\Tank;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/**
 * EVERY admin route x EVERY role. The expected access is written out here, in full, as a table.
 * If a screen is added and nobody decides who may open it, the completeness test fails.
 */
class AccessMatrixTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    private const ALL = ['admin', 'supervisor', 'operator', 'auditor'];

    private const MANAGERS = ['admin', 'supervisor'];

    private const OVERSEERS = ['admin', 'supervisor', 'auditor'];

    private const ADMIN = ['admin'];

    /** route name => roles that may open it. Everyone else must get 403. */
    private function matrix(): array
    {
        $registers = fn (string $slug) => [
            "filament.admin.resources.{$slug}.index" => self::MANAGERS,
            "filament.admin.resources.{$slug}.view" => self::MANAGERS,
            "filament.admin.resources.{$slug}.create" => self::MANAGERS,
            "filament.admin.resources.{$slug}.edit" => self::MANAGERS,
        ];

        return [
            'filament.admin.pages.dashboard' => self::ALL,

            // Operations: everyone sees them; only managers schedule / create
            'filament.admin.resources.vessel-discharges.index' => self::ALL,
            'filament.admin.resources.vessel-discharges.view' => self::ALL,
            'filament.admin.resources.vessel-discharges.run' => self::ALL,   // the wizard: everyone may look, only operators can press
            'filament.admin.resources.vessel-discharges.create' => self::MANAGERS,
            'filament.admin.resources.delivery-orders.index' => self::ALL,
            'filament.admin.resources.delivery-orders.view' => self::ALL,
            'filament.admin.resources.delivery-orders.run' => self::ALL,   // the wizard: everyone may look, only operators can press
            'filament.admin.resources.delivery-orders.create' => self::MANAGERS,
            'filament.admin.resources.truck-visits.index' => self::ALL,
            'filament.admin.resources.truck-visits.view' => self::ALL,
            'filament.admin.resources.correction-requests.index' => self::OVERSEERS,
            'filament.admin.resources.correction-requests.view' => self::OVERSEERS,

            // Stock
            'filament.admin.resources.tanks.index' => self::ALL,
            'filament.admin.resources.tanks.view' => self::ALL,
            'filament.admin.resources.tanks.create' => self::MANAGERS,
            'filament.admin.resources.tanks.edit' => self::MANAGERS,
            'filament.admin.resources.cargo-movements.index' => self::ALL,

            // Reports and administration
            'filament.admin.pages.reports' => self::OVERSEERS,
            'filament.admin.resources.audit-logs.index' => self::OVERSEERS,
            'filament.admin.resources.users.index' => self::ADMIN,
            'filament.admin.resources.users.create' => self::ADMIN,
            'filament.admin.resources.users.edit' => self::ADMIN,

            // Registers
            ...$registers('vessels'), ...$registers('berths'), ...$registers('customers'),
            ...$registers('transporters'), ...$registers('trucks'), ...$registers('drivers'),
        ];
    }

    /** The routes that are plumbing, not screens. */
    private const PLUMBING = ['filament.admin.auth.login', 'filament.admin.auth.logout'];

    /** @return array<string, string> route name => uri (admin GET routes only) */
    private function adminRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (str_starts_with($name, 'filament.admin.') && in_array('GET', $route->methods(), true) && ! in_array($name, self::PLUMBING, true)) {
                $out[$name] = $route->uri();
            }
        }
        ksort($out);

        return $out;
    }

    private function concrete(string $name, string $uri): string
    {
        if (! str_contains($uri, '{record}')) {
            return '/'.$uri;
        }
        $slug = explode('.', $name)[3];

        return '/'.str_replace('{record}', (string) $this->sampleIds()[$slug], $uri);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWithEdgeStates();
    }

    public function test_every_admin_screen_has_an_explicit_access_decision(): void
    {
        $routes = array_keys($this->adminRoutes());
        $decided = array_keys($this->matrix());

        $this->assertSame([], array_values(array_diff($routes, $decided)),
            'These screens exist but nobody decided who may open them. Add them to the matrix.');
        $this->assertSame([], array_values(array_diff($decided, $routes)),
            'The matrix lists screens that no longer exist.');
        $this->assertGreaterThanOrEqual(40, count($routes));
    }

    public function test_every_screen_for_every_role_opens_or_is_refused_exactly_as_decided(): void
    {
        $checked = 0;
        $failures = [];

        foreach ($this->roles() as $role) {
            $this->loginAs($role);

            foreach ($this->adminRoutes() as $name => $uri) {
                $url = $this->concrete($name, $uri);
                $expected = in_array($role->value, $this->matrix()[$name], true) ? 200 : 403;
                $actual = $this->get($url)->getStatusCode();
                $checked++;

                if ($actual !== $expected) {
                    $failures[] = "{$role->value} GET {$url}: expected {$expected}, got {$actual}";
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
        $this->assertGreaterThanOrEqual(160, $checked, 'the matrix should cover 4 roles x 40+ screens');
    }

    public function test_a_missing_record_is_a_404_for_the_allowed_and_never_a_500_for_anyone(): void
    {
        foreach ($this->roles() as $role) {
            $this->loginAs($role);

            foreach ($this->adminRoutes() as $name => $uri) {
                if (! str_contains($uri, '{record}')) {
                    continue;
                }
                $status = $this->get('/'.str_replace('{record}', '999999', $uri))->getStatusCode();

                $this->assertContains($status, [403, 404], "{$role->value} GET {$name} with an unknown id gave {$status}");
                if (in_array($role->value, $this->matrix()[$name], true)) {
                    $this->assertSame(404, $status, "{$role->value} should get a clean 404 on {$name}");
                }
            }
        }
    }

    public function test_guests_are_sent_to_the_login_for_every_screen_and_for_the_pdf(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        foreach ($this->adminRoutes() as $name => $uri) {
            $this->get($this->concrete($name, $uri))->assertRedirect('/admin/login');
        }
        $this->get('/reports/pdf?from=2026-11-01&until=2026-11-30')->assertRedirect('/admin/login');
    }

    public function test_filament_built_in_download_routes_are_never_open_or_broken(): void
    {
        // The panel registers generic export/import download routes. We do not use them; they must not leak or crash.
        foreach (['/filament/exports/1/download', '/filament/imports/1/failed-rows/download'] as $url) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $this->assertContains($this->get($url)->getStatusCode(), [302, 401, 403, 404], "guest {$url}");

            foreach ($this->roles() as $role) {
                $this->loginAs($role);
                $this->assertContains($this->get($url)->getStatusCode(), [302, 403, 404], "{$role->value} {$url}");
            }
        }
    }

    public function test_the_pdf_follows_the_reports_rule_for_every_role(): void
    {
        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            $status = $this->get('/reports/pdf?from=2026-11-01&until=2026-11-30')->getStatusCode();

            $this->assertSame(in_array($role->value, self::OVERSEERS, true) ? 200 : 403, $status, "{$role->value} pdf");
        }
    }

    public function test_write_requests_cannot_bypass_the_matrix(): void
    {
        // The only POSTs a person can send are Livewire updates, which are authorised per action (see ActionMatrixTest),
        // and logout. A bare POST to a resource URL must never create or change anything.
        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            $before = [Vessel::count(), User::count(), Tank::count()];

            foreach (['/admin/vessels', '/admin/users', '/admin/tanks'] as $url) {
                $this->post($url, ['name' => 'Injected', 'email' => 'x@y.z', 'code' => 'X'])->assertStatus(405);
            }

            $this->assertSame($before, [Vessel::count(), User::count(), Tank::count()]);
        }
    }
}
