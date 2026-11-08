<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Cargo\Services\TankLedger;
use App\Domain\Operations\Actions\AdvanceDischargeStatus;
use App\Domain\Operations\Actions\RecordDischargeIntoTank;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Exceptions\OperationIncompleteException;
use App\Filament\Resources\VesselDischargeResource\Pages\ViewVesselDischarge;
use App\Models\Tank;
use App\Models\User;
use App\Models\VesselDischarge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/** A voyage cannot be marked complete while most of its cargo is still on the ship. */
class EarlyCompletionTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    private function voyage(int $bl = 1_000_000): VesselDischarge
    {
        return VesselDischarge::create(['voyage_ref' => 'V-'.uniqid(), 'vessel_name' => 'MV Test', 'commodity' => Commodity::CPOL, 'bl_quantity_kg' => $bl, 'status' => DischargeStatus::Discharging]);
    }

    private function pump(VesselDischarge $v, int $kg): void
    {
        $tank = Tank::where('commodity', Commodity::CPOL->value)->where('capacity_kg', 50_000_000)->first() ?? Tank::create(['code' => 'T-'.uniqid(), 'name' => 'T', 'commodity' => Commodity::CPOL, 'capacity_kg' => 50_000_000]);
        app(RecordDischargeIntoTank::class)($v, $tank->id, $kg, Carbon::parse('2026-11-05 08:00'), uniqid());
    }

    private function user(Role $role): User
    {
        return User::factory()->role($role)->create();
    }

    public function test_ten_percent_cannot_be_completed_by_an_operator_or_by_nobody(): void
    {
        $v = $this->voyage();
        $this->pump($v, 100_000);

        foreach ([$this->user(Role::Operator), $this->user(Role::Auditor), null] as $who) {
            try {
                app(AdvanceDischargeStatus::class)($v, DischargeStatus::Completed, null, 'looks done to me', $who);
                $this->fail('a voyage at 10% was completed');
            } catch (OperationIncompleteException $e) {
                $this->assertStringContainsString('10%', $e->getMessage());
            }
        }
        $this->assertSame(DischargeStatus::Discharging, $v->fresh()->status);
    }

    public function test_a_supervisor_needs_a_reason_and_it_is_kept(): void
    {
        $v = $this->voyage();
        $this->pump($v, 100_000);

        foreach (['', '   ', null] as $reason) {
            try {
                app(AdvanceDischargeStatus::class)($v, DischargeStatus::Completed, null, $reason, $this->user(Role::Supervisor));
                $this->fail('closed early without a reason');
            } catch (OperationIncompleteException $e) {
                $this->assertStringContainsString('reason', $e->getMessage());
            }
        }

        app(AdvanceDischargeStatus::class)($v, DischargeStatus::Completed, null, 'Ship sailed with the balance after a pump failure', $this->user(Role::Admin));
        $v->refresh();
        $this->assertSame(DischargeStatus::Completed, $v->status);
        $this->assertSame('Ship sailed with the balance after a pump failure', $v->completion_note);
    }

    public function test_ninety_nine_percent_counts_as_fully_discharged_for_everyone(): void
    {
        $exact = $this->voyage();
        $this->pump($exact, 990_000);                                            // exactly 99%
        app(AdvanceDischargeStatus::class)($exact, DischargeStatus::Completed, null, null, $this->user(Role::Operator));
        $this->assertSame(DischargeStatus::Completed, $exact->fresh()->status);
        $this->assertNull($exact->fresh()->completion_note);

        $short = $this->voyage();
        $this->pump($short, 989_999);                                            // one kilogram under
        $this->expectException(OperationIncompleteException::class);
        app(AdvanceDischargeStatus::class)($short, DischargeStatus::Completed, null, null, $this->user(Role::Operator));
    }

    public function test_reversed_cargo_does_not_count_towards_the_target(): void
    {
        $v = $this->voyage();
        $this->pump($v, 1_000_000);
        $movement = $v->movements()->first();
        app(TankLedger::class)->reverse($movement, Carbon::parse('2026-11-05 09:00'), 'rev-'.uniqid());

        $this->expectException(OperationIncompleteException::class);
        app(AdvanceDischargeStatus::class)($v->fresh(), DischargeStatus::Completed, null, null, $this->user(Role::Operator));
    }

    public function test_on_screen_an_operator_is_refused_and_a_supervisor_must_give_a_reason(): void
    {
        $this->seedWithEdgeStates();
        $voyage = VesselDischarge::where('voyage_ref', 'VOY-2611-03')->firstOrFail();          // 85% received
        $this->assertFalse($voyage->isFullyDischarged());

        $this->loginAs(Role::Operator);
        Livewire::test(ViewVesselDischarge::class, ['record' => $voyage->id])
            ->callAction('to_completed')->assertNotified('Rejected');
        $this->assertSame(DischargeStatus::Discharging, $voyage->fresh()->status);

        $this->loginAs(Role::Supervisor);
        Livewire::test(ViewVesselDischarge::class, ['record' => $voyage->id])
            ->callAction('to_completed', ['shortfall_reason' => ''])->assertHasActionErrors(['shortfall_reason' => 'required']);
        $this->assertSame(DischargeStatus::Discharging, $voyage->fresh()->status);

        Livewire::test(ViewVesselDischarge::class, ['record' => $voyage->id])
            ->callAction('to_completed', ['shortfall_reason' => 'Balance rolled to the next call'])->assertNotified('Discharge is now Completed');
        $this->assertSame('Balance rolled to the next call', $voyage->fresh()->completion_note);
    }
}
