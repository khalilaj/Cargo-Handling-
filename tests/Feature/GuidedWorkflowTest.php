<?php

namespace Tests\Feature;

use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Filament\Resources\DeliveryOrderResource\Pages\RunDelivery;
use App\Filament\Resources\VesselDischargeResource\Pages\ListVesselDischarges;
use App\Filament\Resources\VesselDischargeResource\Pages\RunDischarge;
use App\Filament\Resources\VesselDischargeResource\Pages\ViewVesselDischarge;
use App\Filament\Support\WizardFields;
use App\Filament\Support\WorkflowSteps;
use App\Models\DeliveryOrder;
use App\Models\TruckVisit;
use App\Models\User;
use App\Models\VesselDischarge;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The guidance layer: dashboard guide, progress steppers, screen tips and the visible transit loss. */
class GuidedWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', 'admin')->first());
    }

    public function test_discharge_steps_mark_one_current_step_and_say_what_to_press_next(): void
    {
        $expect = [
            DischargeStatus::Scheduled->value => [0, 'Mark berthed'],
            DischargeStatus::Berthed->value => [1, 'Start discharge'],
            DischargeStatus::Discharging->value => [2, 'Pump into tank'],
        ];

        foreach ($expect as $status => [$currentIndex, $button]) {
            $record = VesselDischarge::where('status', $status)->firstOrFail();
            $flow = WorkflowSteps::for($record);

            $this->assertSame('current', $flow['steps'][$currentIndex]['state'], $status);
            $this->assertSame(1, collect($flow['steps'])->where('state', 'current')->count(), $status);
            $this->assertStringContainsString($button, $flow['hint'], $status);
        }
    }

    public function test_completed_and_cancelled_records_have_no_current_step(): void
    {
        $record = VesselDischarge::first();

        $record->status = DischargeStatus::Completed;
        $done = WorkflowSteps::for($record);
        $this->assertSame(['done', 'done', 'done', 'done'], array_column($done['steps'], 'state'));
        $this->assertStringStartsWith('Done.', $done['hint']);

        $record->status = DischargeStatus::Cancelled;
        $cancelled = WorkflowSteps::for($record);
        $this->assertTrue($cancelled['cancelled']);
        $this->assertSame([], collect($cancelled['steps'])->where('state', '!=', 'todo')->all());
    }

    public function test_order_and_truck_visit_steps_follow_their_status(): void
    {
        $order = DeliveryOrder::where('status', OrderStatus::Pending->value)->firstOrFail();
        $this->assertStringContainsString('Start loading', WorkflowSteps::for($order)['hint']);
        $this->assertSame('current', WorkflowSteps::for($order)['steps'][0]['state']);

        $visit = TruckVisit::firstOrFail();
        foreach ([[VisitStatus::Arrived, 'Weigh in'], [VisitStatus::WeighedIn, 'Start loading'], [VisitStatus::WeighedOut, 'Gate out']] as [$status, $button]) {
            $visit->status = $status;
            $this->assertStringContainsString($button, WorkflowSteps::for($visit)['hint']);
        }
    }

    public function test_voyage_page_shows_the_stepper_and_next_step(): void
    {
        $v = VesselDischarge::where('status', DischargeStatus::Berthed->value)->firstOrFail();

        Livewire::test(ViewVesselDischarge::class, ['record' => $v->id])
            ->assertSee('Next: press "Start discharge"')
            ->assertSee('Scheduled')
            ->assertSee('Completed');
    }

    public function test_dashboard_has_no_how_to_guide_for_any_role(): void
    {
        $widgets = collect(Filament::getPanel('admin')->getWidgets())->map(fn (string $w) => class_basename($w));

        $this->assertFalse($widgets->contains('WorkflowGuide'));
    }

    public function test_screens_carry_no_tip_banners(): void
    {
        foreach (['/admin', '/admin/vessel-discharges', '/admin/delivery-orders', '/admin/truck-visits', '/admin/tanks'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('Got it');
        }
    }

    public function test_finishing_a_step_pops_up_what_to_do_next(): void
    {
        $v = VesselDischarge::where('status', DischargeStatus::Berthed->value)->firstOrFail();

        $page = Livewire::test(RunDischarge::class, ['record' => $v->id])->call('start');

        $next = WorkflowSteps::for($v->fresh())['hint'];
        $this->assertStringContainsString('Pump into tank', $next);
        $page->assertNotified(Notification::make()->title('Discharge started')->body($next)->success()->duration(8000));
    }

    public function test_truck_steps_pop_up_the_next_button(): void
    {
        $visit = TruckVisit::where('status', VisitStatus::Arrived->value)->first();
        $this->assertNotNull($visit, 'the seed has a truck that has just arrived');

        $page = Livewire::test(RunDelivery::class, ['record' => $visit->delivery_order_id])
            ->call('selectVisit', $visit->id)->set('weighInData.kg', 15_000)->call('weighIn');

        $next = WorkflowSteps::for($visit->fresh())['hint'];
        $this->assertStringContainsString('Start loading', $next);
        $page->assertNotified(Notification::make()->title('Weighed in')->body($next)->success()->duration(8000));
    }

    public function test_discharge_list_shows_the_transit_loss(): void
    {
        $v = VesselDischarge::where('status', DischargeStatus::Discharging->value)->firstOrFail();

        Livewire::test(ListVesselDischarges::class)
            ->assertSee('Loss (kg)')
            ->assertSee(number_format($v->transitLossKg()));
    }

    public function test_pump_form_previews_the_transit_loss_in_plain_words(): void
    {

        $this->assertStringContainsString('Enter a quantity', WizardFields::lossPreview(0));
        $text = WizardFields::lossPreview(1_000_000);
        $this->assertStringContainsString('1,000,000 kg', $text);
        $this->assertStringContainsString('5,000 kg is lost in transit (0.5%)', $text);
        $this->assertStringContainsString('995,000 kg goes into the tank', $text);
    }
}
