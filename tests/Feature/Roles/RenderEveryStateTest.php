<?php

namespace Tests\Feature\Roles;

use App\Domain\Access\Role;
use App\Models\CargoMovement;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/** No screen may break on an empty install, on any record in any status, or when a person's role changes. */
class RenderEveryStateTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    /** @return list<string> GET urls of the panel that need no record id */
    private function staticUrls(): array
    {
        $urls = [];
        foreach (app('router')->getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'filament.admin.') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (str_contains($route->uri(), '{') || str_contains($route->uri(), 'login') || str_contains($route->uri(), 'filament/')) {
                continue;
            }
            $urls[$name] = '/'.ltrim($route->uri(), '/');
        }

        return $urls;
    }

    public function test_every_screen_renders_on_an_empty_install_for_every_role(): void
    {
        $this->seedUsersOnly();
        $this->assertNotEmpty($this->staticUrls());

        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            foreach ($this->staticUrls() as $name => $url) {
                $status = $this->get($url)->getStatusCode();
                $this->assertContains($status, [200, 403], "{$role->value} {$url} ({$name}) gave {$status} on an empty install");
            }
        }
    }

    public function test_every_record_of_every_resource_opens_for_each_role_that_may_view_it(): void
    {
        $this->seedWithEdgeStates();
        $checked = 0;

        foreach ($this->roles() as $role) {
            $user = $this->loginAs($role);
            foreach (Filament::getPanel('admin')->getResources() as $resource) {
                if (! $resource::hasPage('view') && ! $resource::hasPage('edit')) {
                    continue;
                }
                foreach ($resource::getEloquentQuery()->limit(60)->get() as $record) {
                    $page = $resource::hasPage('view') ? 'view' : 'edit';
                    $url = $resource::getUrl($page, ['record' => $record]);
                    $allowed = $user->can('view', $record);
                    $status = $this->get($url)->getStatusCode();
                    $this->assertSame($allowed ? 200 : 403, $status, "{$role->value} {$url}");
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(200, $checked);
    }

    public function test_create_and_edit_pages_follow_rights(): void
    {
        $this->seedWithEdgeStates();
        foreach ($this->roles() as $role) {
            $user = $this->loginAs($role);
            foreach (Filament::getPanel('admin')->getResources() as $resource) {
                if ($resource::hasPage('create')) {
                    $can = $user->can('create', $resource::getModel());
                    $this->assertSame($can ? 200 : 403, $this->get($resource::getUrl('create'))->getStatusCode(), "{$role->value} create ".class_basename($resource));
                }
                if ($resource::hasPage('edit') && ($record = $resource::getEloquentQuery()->first())) {
                    $can = $user->can('update', $record);
                    $this->assertSame($can ? 200 : 403, $this->get($resource::getUrl('edit', ['record' => $record]))->getStatusCode(), "{$role->value} edit ".class_basename($resource));
                }
            }
        }
    }

    public function test_changing_a_users_role_takes_effect_on_the_next_request(): void
    {
        $this->seedWithEdgeStates();
        $user = User::where('role', Role::Operator->value)->firstOrFail();

        $this->actingAs($user);
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/vessels')->assertForbidden();

        $user->update(['role' => Role::Admin]);
        $this->app->forgetInstance(NavigationManager::class);
        $this->actingAs($user->fresh());
        $this->get('/admin/users')->assertOk();
        $this->get('/admin/vessels')->assertOk();

        $user->update(['role' => Role::Auditor]);
        $this->app->forgetInstance(NavigationManager::class);
        $this->actingAs($user->fresh());
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/audit-logs')->assertOk();
    }

    public function test_dashboard_stays_within_a_query_budget_for_every_role(): void
    {
        $this->seedWithEdgeStates();
        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/admin')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertLessThan(150, $count, "{$role->value} dashboard ran {$count} queries");
        }
    }

    public function test_ledger_is_untouched_by_all_this_browsing(): void
    {
        $this->seedWithEdgeStates();
        $before = [CargoMovement::count(), (int) CargoMovement::sum('net_kg')];
        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            foreach ($this->staticUrls() as $url) {
                $this->get($url);
            }
        }
        $this->assertSame($before, [CargoMovement::count(), (int) CargoMovement::sum('net_kg')]);
    }

    public function test_ingest_api_still_refuses_anyone_without_the_token(): void
    {
        $this->seedUsersOnly();
        foreach ($this->roles() as $role) {
            $this->loginAs($role);
            $status = $this->postJson('/api/movements/ingest', [])->getStatusCode();
            $this->assertContains($status, [401, 403], "a signed-in {$role->value} must not bypass the ingest token (got {$status})");
        }
    }
}
