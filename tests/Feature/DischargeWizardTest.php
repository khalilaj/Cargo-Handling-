<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Operations\Actions\AdvanceDischargeStatus;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Filament\Resources\VesselDischargeResource;
use App\Filament\Resources\VesselDischargeResource\Pages\CreateVesselDischarge;
use App\Filament\Resources\VesselDischargeResource\Pages\RunDischarge;
use App\Filament\Resources\VesselDischargeResource\Pages\ViewVesselDischarge;
use App\Models\Berth;
use App\Models\Tank;
use App\Models\Vessel;
use App\Models\VesselDischarge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/** The discharge wizard: one page that walks a voyage through berth, start, pump and complete. */
class DischargeWizardTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWithEdgeStates();
        $this->loginAs(Role::Operator);
    }

    private function voyage(string $ref): VesselDischarge
    {
        return VesselDischarge::where('voyage_ref', $ref)->firstOrFail();
    }

    private function wizard(VesselDischarge $v)
    {
        return Livewire::test(RunDischarge::class, ['record' => $v->id]);
    }

    private function pump($wizard, int $tankId, int $kg, string $key)
    {
        return $wizard->set('pumpData.tank_id', $tankId)->set('pumpData.quantity_kg', $kg)
            ->set('pumpData.occurred_at', now()->toDateTimeString())->set('pumpData.idempotency_key', $key)->call('pump');
    }

    public function test_it_opens_on_the_current_step_for_every_status(): void
    {
        foreach ([
            'VOY-2611-05' => 'Berth the ship',          // scheduled
            'VOY-2611-04' => 'Start the discharge',     // berthed
            'VOY-2611-03' => 'Pump into a tank',        // discharging
            'VOY-2611-01' => 'Voyage complete',         // completed
            'VOY-EDGE-1' => 'Voyage cancelled',         // cancelled
        ] as $ref => $heading) {
            $this->wizard($this->voyage($ref))->assertOk()->assertSee($heading);
        }
    }

    public function test_a_voyage_runs_from_berth_to_an_early_close_with_a_reason(): void
    {
        $berth = Berth::firstOrCreate(['code' => 'B-WIZ'], ['name' => 'Wizard test berth', 'active' => true]);
        $v = $this->voyage('VOY-2611-05');                                   // scheduled, CPOL
        $cpol = Tank::where('commodity', Commodity::CPOL->value)->firstOrFail();

        $wizard = $this->wizard($v)->set('berthData.berth_id', $berth->id)->call('berth')->assertNotified('Ship berthed');
        $this->assertSame(DischargeStatus::Berthed, $v->fresh()->status);

        $wizard->call('start')->assertNotified('Discharge started')->assertSee('Pump into a tank');
        $this->assertSame(DischargeStatus::Discharging, $v->fresh()->status);

        $this->pump($wizard, $cpol->id, 100_000, 'wiz-1')->assertNotified('Cargo pumped into tank')->assertSee('Pumped so far');
        $this->assertSame(500, $v->fresh()->transitLossKg());                // the 0.5% came off

        $this->loginAs(Role::Supervisor);                                     // closing at 5% is a supervisor's call
        $this->wizard($v)->call('finishPumping')->assertSee('Less than 99%')
            ->set('completeData.shortfall_reason', 'Ship sailed after a pump failure')->call('complete')->assertNotified('Voyage completed');

        $v->refresh();
        $this->assertSame(DischargeStatus::Completed, $v->status);
        $this->assertSame('Ship sailed after a pump failure', $v->completion_note);
    }

    public function test_pumping_the_rest_of_the_cargo_lets_an_operator_complete_without_a_reason(): void
    {
        $v = $this->voyage('VOY-2611-03');                                   // PFAD, 300,000 kg still on the ship
        $pfad = Tank::where('code', 'PFAD-01')->firstOrFail();

        $wizard = $this->pump($this->wizard($v), $pfad->id, 300_000, 'wiz-2')->assertNotified('Cargo pumped into tank');
        $wizard->call('finishPumping')->assertSee('Press Complete')->call('complete')->assertNotified('Voyage completed');

        $this->assertSame(DischargeStatus::Completed, $v->fresh()->status);
        $this->assertNull($v->fresh()->completion_note);
        $wizard->assertSee('Voyage complete')->assertSee('is in the tanks after');
    }

    public function test_an_operator_cannot_close_early_and_is_told_why(): void
    {
        $v = $this->voyage('VOY-2611-03');                                   // 85% received

        $this->wizard($v)->call('finishPumping')->assertSee('only a supervisor can close it')->call('complete')->assertNotified('Not done');
        $this->assertSame(DischargeStatus::Discharging, $v->fresh()->status);
    }

    public function test_cancel_keeps_its_confirmation_and_cancels(): void
    {
        $v = $this->voyage('VOY-2611-05');

        $this->wizard($v)->callAction('cancel_voyage')->assertNotified('Voyage cancelled');
        $this->assertSame(DischargeStatus::Cancelled, $v->fresh()->status);
    }

    public function test_an_auditor_can_look_but_not_press_anything(): void
    {
        $this->loginAs(Role::Auditor);

        $this->wizard($this->voyage('VOY-2611-05'))->assertSee('You can look at this voyage but not change it')->assertSee('Mark berthed and continue');   // same step, switched off

        $v = $this->voyage('VOY-2611-04');
        $this->wizard($v)->call('start')->assertForbidden();
        $this->assertSame(DischargeStatus::Berthed, $v->fresh()->status);
    }

    public function test_a_step_taken_elsewhere_lands_on_the_real_current_step(): void
    {
        $v = $this->voyage('VOY-2611-04');
        $wizard = $this->wizard($v);                                         // this tab still shows "Start the discharge"

        app(AdvanceDischargeStatus::class)($v->fresh(), DischargeStatus::Discharging);   // a colleague pressed it first

        $wizard->call('start')->assertNotified('Not done')->assertSee('Pump into a tank')->assertDontSee('Start the discharge');
    }

    public function test_the_forward_button_leads_to_the_wizard_instead_of_a_window(): void
    {
        $v = $this->voyage('VOY-2611-03');

        Livewire::test(ViewVesselDischarge::class, ['record' => $v->id])
            ->assertActionVisible('continue')
            ->assertActionHasUrl('continue', VesselDischargeResource::getUrl('run', ['record' => $v]));

        $this->get(VesselDischargeResource::getUrl('run', ['record' => $v]))->assertOk();
    }

    public function test_scheduling_a_voyage_opens_the_wizard_on_its_first_step(): void
    {
        $this->loginAs(Role::Supervisor);                                    // scheduling a voyage is a supervisor's job

        $page = Livewire::test(CreateVesselDischarge::class)
            ->fillForm(['vessel_id' => Vessel::first()->id, 'commodity' => Commodity::CPO->value, 'bl_quantity_kg' => 500_000])
            ->call('create')->assertHasNoFormErrors();

        $new = VesselDischarge::latest('id')->first();
        $this->assertSame(DischargeStatus::Scheduled, $new->status);
        $page->assertRedirect(VesselDischargeResource::getUrl('run', ['record' => $new]));
    }

    public function test_every_role_sees_the_same_process(): void
    {
        $expect = [
            'VOY-2611-05' => ['Berth the ship', 'Mark berthed and continue'],
            'VOY-2611-04' => ['Start the discharge', 'Start discharge'],
            'VOY-2611-03' => ['Pump into a tank', 'Pump into tank', 'Finish pumping early'],
            'VOY-2611-01' => ['Voyage complete'],
        ];

        foreach (Role::cases() as $role) {
            $this->loginAs($role);
            foreach ($expect as $ref => $texts) {
                $wizard = $this->wizard($this->voyage($ref))->assertSee('Scheduled')->assertSee('Discharging')->assertSee('Completed');
                foreach ($texts as $text) {
                    $wizard->assertSee($text, escape: true);
                }
            }
        }
    }

    public function test_the_early_close_step_looks_the_same_for_every_role_but_only_supervisors_can_use_it(): void
    {
        foreach (Role::cases() as $role) {
            $this->loginAs($role);
            $this->wizard($this->voyage('VOY-2611-03'))->call('finishPumping')
                ->assertSee('Complete the voyage')->assertSee('Why is it being closed early?')->assertSee('Complete voyage');
        }

        $v = $this->voyage('VOY-2611-03');
        $this->loginAs(Role::Operator);
        $this->wizard($v)->call('finishPumping')->set('completeData.shortfall_reason', 'Trying anyway')->call('complete')->assertNotified('Not done');
        $this->assertSame(DischargeStatus::Discharging, $v->fresh()->status);
    }
}
