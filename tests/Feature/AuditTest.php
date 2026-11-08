<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Cargo\Services\TankLedger;
use App\Domain\Operations\Actions\AdvanceDischargeStatus;
use App\Domain\Operations\Actions\RecordDischargeIntoTank;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogResource\Pages\ViewAuditLog;
use App\Filament\Resources\TankResource;
use App\Models\AuditLog;
use App\Models\CargoMovement;
use App\Models\Tank;
use App\Models\User;
use App\Models\VesselDischarge;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    private function login(Role $role): User
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $u = User::where('role', $role->value)->firstOrFail();
        $this->actingAs($u);

        return $u;
    }

    public function test_changes_are_logged_with_who_and_before_after(): void
    {
        $this->seed(DatabaseSeeder::class);
        $sup = $this->login(Role::Supervisor);

        $tank = Tank::where('code', 'CPO-01')->first();
        $tank->update(['capacity_kg' => 5_500_000, 'name' => 'Crude Palm Oil One']);

        $log = AuditLog::where('event', 'tank.updated')->latest('id')->first();
        $this->assertSame($sup->id, $log->user_id);
        $this->assertSame('Shift Supervisor', $log->actor_name);
        $this->assertSame('supervisor', $log->actor_role);
        $this->assertSame(5_000_000, (int) $log->old_values['capacity_kg']);
        $this->assertSame(5_500_000, (int) $log->new_values['capacity_kg']);
        $this->assertSame('Tank', $log->subject_type);
        $this->assertStringContainsString('Tank CPO-01', $log->description);
    }

    public function test_lifecycle_and_movements_are_attributed_to_the_operator(): void
    {
        $this->seed(DatabaseSeeder::class);
        $op = $this->login(Role::Operator);

        $v = VesselDischarge::where('status', DischargeStatus::Berthed->value)->first();
        $v = app(AdvanceDischargeStatus::class)($v, DischargeStatus::Discharging);
        app(RecordDischargeIntoTank::class)($v, Tank::where('code', 'CPO-02')->value('id'), 100_000, Carbon::now(), 'aud-1');

        $status = AuditLog::where('event', 'vessel_discharge.updated')->latest('id')->first();
        $this->assertSame($op->id, $status->user_id);
        $this->assertSame('berthed', $status->old_values['status']);
        $this->assertSame('discharging', $status->new_values['status']);

        $move = AuditLog::where('event', 'cargo_movement.created')->latest('id')->first();
        $this->assertSame('operator', $move->actor_role);
        $this->assertStringContainsString('99,500 kg net', $move->description);
    }

    public function test_the_derived_tank_balance_is_not_logged_as_a_tank_edit(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->login(Role::Operator);
        $before = AuditLog::where('event', 'tank.updated')->count();

        $v = VesselDischarge::where('status', DischargeStatus::Discharging->value)->first();
        app(RecordDischargeIntoTank::class)($v, Tank::where('code', 'PFAD-01')->value('id'), 10_000, Carbon::now(), 'aud-2');

        $this->assertSame($before, AuditLog::where('event', 'tank.updated')->count());
    }

    public function test_rows_are_immutable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $row = AuditLog::first();

        try {
            $row->update(['description' => 'tampered']);
            $this->fail('update should throw');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $row->delete();
            $this->fail('delete should throw');
        } catch (LogicException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        $this->assertSame(AuditLog::count(), AuditLog::count());
        $this->assertNotSame('tampered', $row->fresh()->description);
    }

    public function test_password_changes_are_logged_but_never_the_value(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->login(Role::Admin);

        User::where('role', 'operator')->first()->update(['password' => 'a-brand-new-secret']);

        $log = AuditLog::where('event', 'user.password_changed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->new_values);
        $this->assertStringNotContainsString('secret', json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString('$2y$', json_encode(AuditLog::all()->toArray()));   // no hashes either
    }

    public function test_role_changes_are_logged(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->login(Role::Admin);

        User::where('role', 'operator')->first()->update(['role' => Role::Supervisor]);

        $log = AuditLog::where('event', 'user.updated')->latest('id')->first();
        $this->assertSame('operator', $log->old_values['role']);
        $this->assertSame('supervisor', $log->new_values['role']);
    }

    public function test_changes_without_a_person_are_attributed_to_system(): void
    {
        $this->seed(DatabaseSeeder::class);

        $log = AuditLog::where('event', 'tank.created')->first();
        $this->assertSame('system', $log->actor_name);
        $this->assertNull($log->user_id);
    }

    public function test_reseeding_adds_no_noise(): void
    {
        $this->seed(DatabaseSeeder::class);
        $count = AuditLog::count();

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($count, AuditLog::count());
    }

    public function test_who_can_open_the_audit_log(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ([Role::Admin, Role::Supervisor, Role::Auditor] as $r) {
            $this->login($r);
            $this->get('/admin/audit-logs')->assertOk();
        }

        $this->login(Role::Operator);
        $this->get('/admin/audit-logs')->assertForbidden();
    }

    public function test_the_audit_screen_lists_and_filters_entries(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->login(Role::Auditor);
        Tank::where('code', 'CPO-01')->first()->update(['name' => 'Renamed tank']);

        Livewire::test(ListAuditLogs::class)
            ->assertSee('Tank CPO-01 name')
            ->filterTable('event', 'tank.updated')
            ->assertSee('Renamed tank')
            ->assertDontSee('Movement #');
    }

    public function test_descriptions_are_readable_for_enums_and_reversals(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->login(Role::Operator);

        $v = VesselDischarge::where('status', DischargeStatus::Berthed->value)->first();
        app(AdvanceDischargeStatus::class)($v, DischargeStatus::Discharging);

        $d = AuditLog::where('event', 'vessel_discharge.updated')->latest('id')->first()->description;
        $this->assertStringContainsString('status: berthed → discharging', $d);        // not "…"

        $original = CargoMovement::where('idempotency_key', 'seed-6')->first();
        app(TankLedger::class)->reverse($original, now()->addMinute(), 'rev-test');
        $this->assertStringContainsString("reversal of #{$original->id}", AuditLog::where('event', 'cargo_movement.created')->latest('id')->first()->description);
    }

    public function test_the_audit_table_reads_changes_and_links_to_the_record(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->login(Role::Auditor);
        $tank = Tank::where('code', 'CPO-01')->first();
        $tank->update(['capacity_kg' => 5_500_000]);

        Livewire::test(ListAuditLogs::class)
            ->filterTable('event', 'tank.updated')
            ->assertSee('Capacity kg: 5000000 → 5500000')
            ->assertSee('CPO-01')
            ->assertSee(TankResource::getUrl('view', ['record' => $tank]));
    }

    public function test_the_audit_table_says_who_and_what_for_creations_and_password_changes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->login(Role::Admin);
        User::where('role', 'operator')->first()->update(['password' => 'a-brand-new-secret']);

        Livewire::test(ListAuditLogs::class)
            ->filterTable('event', 'user.password_changed')
            ->assertSee('Password changed')
            ->assertSee('value not recorded');

        Livewire::test(ListAuditLogs::class)
            ->filterTable('event', 'tank.created')
            ->assertSee('System');
    }

    public function test_a_deleted_record_is_named_without_a_broken_link(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->login(Role::Admin);
        $temp = User::factory()->create(['role' => 'operator']);
        $id = $temp->id;
        $temp->delete();

        Livewire::test(ListAuditLogs::class)
            ->filterTable('event', 'user.deleted')
            ->assertSee("#{$id}")
            ->assertDontSee(route('filament.admin.resources.users.index').'/'.$id);
    }

    public function test_the_entry_page_shows_the_changes_as_a_list(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->login(Role::Supervisor);
        Tank::where('code', 'CPO-01')->first()->update(['capacity_kg' => 5_500_000]);
        $log = AuditLog::where('event', 'tank.updated')->latest('id')->first();

        $this->get(AuditLogResource::getUrl('view', ['record' => $log]))->assertOk();

        Livewire::test(ViewAuditLog::class, ['record' => $log->getKey()])
            ->assertSee('Capacity kg: 5000000 → 5500000')
            ->assertDontSee('old_values');
    }
}
