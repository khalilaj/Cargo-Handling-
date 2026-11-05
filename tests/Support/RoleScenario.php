<?php

namespace Tests\Support;

use App\Domain\Access\Role;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Corrections\Actions\ApproveCorrection;
use App\Domain\Corrections\Actions\RejectCorrection;
use App\Domain\Corrections\Actions\RequestCorrection;
use App\Domain\Fleet\Actions\TruckVisitWorkflow;
use App\Domain\Operations\Actions\AdvanceDischargeStatus;
use App\Domain\Operations\Actions\AdvanceOrderStatus;
use App\Domain\Operations\Actions\RecordTruckLoad;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
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
use Database\Seeders\DatabaseSeeder;
use Filament\Navigation\NavigationManager;
use Illuminate\Support\Carbon;

/**
 * Shared set-up for the role-coverage suite: sign in as a role, and build a database that contains
 * EVERY status and awkward state the screens can meet, so a page that only breaks on (say) a cancelled
 * visit or a reversed movement cannot hide.
 */
trait RoleScenario
{
    /** The fixed "today" for these tests: the demo documents carry absolute dates. */
    protected const NOW = '2026-11-05 10:00:00';

    /** @return list<Role> */
    protected function roles(): array
    {
        return Role::cases();
    }

    protected function loginAs(Role $role): User
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        // Production builds the menu per request; inside one test process Filament would reuse the last user's.
        $this->app->forgetInstance(NavigationManager::class);

        $this->actingAs($user = User::where('role', $role->value)->firstOrFail());

        return $user;
    }

    protected function freezeClock(): void
    {
        $this->travelTo(Carbon::parse(self::NOW));
    }

    /** The demo data only. */
    protected function seedDemo(): void
    {
        $this->freezeClock();
        $this->seed(DatabaseSeeder::class);
    }

    /** A bare install: the four users and nothing else, as on the very first day. */
    protected function seedUsersOnly(): void
    {
        $this->freezeClock();
        foreach ($this->roles() as $role) {
            User::factory()->role($role)->create(['email' => "{$role->value}@cargo.test", 'name' => ucfirst($role->value)]);
        }
    }

    /**
     * The demo data PLUS one of everything awkward: cancelled and completed voyages/orders, departed and
     * cancelled truck visits, a reversed movement with an approved correction, a rejected correction,
     * deactivated registers, a nearly-full and a full tank, and a truck that has overstayed.
     */
    protected function seedWithEdgeStates(): void
    {
        $this->seedDemo();

        $operator = User::where('role', Role::Operator->value)->first();
        $supervisor = User::where('role', Role::Supervisor->value)->first();
        $flow = app(TruckVisitWorkflow::class);
        $cpol = Tank::where('code', 'CPOL-01')->first();
        $loadingOrder = DeliveryOrder::where('order_ref', 'ORD-1003')->first();

        auth()->setUser($operator);

        // a cancelled voyage and a cancelled order
        $ship = Vessel::create(['name' => 'MV Edge Case', 'imo' => '9000009']);
        $voyage = VesselDischarge::create(['voyage_ref' => 'VOY-EDGE-1', 'vessel_id' => $ship->id, 'commodity' => Commodity::CPO, 'bl_quantity_kg' => 1_000_000]);
        app(AdvanceDischargeStatus::class)($voyage, DischargeStatus::Cancelled);

        $order = DeliveryOrder::create(['order_ref' => 'ORD-EDGE-1', 'customer_id' => Customer::first()->id, 'commodity' => Commodity::CPOL, 'ordered_kg' => 5_000]);
        app(AdvanceOrderStatus::class)($order, OrderStatus::Cancelled);

        // a truck that completed a whole visit, and one whose visit was cancelled
        $done = Truck::create(['plate' => 'KEDGE 1A', 'capacity_kg' => 30_000, 'tare_kg' => 14_000, 'insurance_expiry' => '2027-06-30', 'inspection_expiry' => '2027-06-30']);
        $visit = $flow->gateIn($loadingOrder, $done);
        $flow->weighIn($visit, 14_000);
        $flow->startLoading($visit);
        $load = app(RecordTruckLoad::class)($loadingOrder, $cpol->id, 10_000, now(), 'edge-load-1', null, $visit);
        $flow->gateOut($flow->weighOut($visit, 24_000));

        $stopped = Truck::create(['plate' => 'KEDGE 2B', 'capacity_kg' => 30_000]);
        $flow->cancel($flow->gateIn($loadingOrder, $stopped));

        // an approved correction (reversal) and a rejected one
        $approved = app(RequestCorrection::class)($load, $operator, 'Loaded from the wrong tank, please reverse.');
        app(ApproveCorrection::class)($approved, $supervisor, 'Checked with the weighbridge ticket.');

        $rejected = app(RequestCorrection::class)(CargoMovement::where('idempotency_key', 'seed-4')->first(), $operator, 'I think the quantity was wrong.');
        app(RejectCorrection::class)($rejected, $supervisor, 'The ticket matches, no error.');

        auth()->forgetGuards();

        // deactivated registers (history keeps pointing at them)
        Vessel::create(['name' => 'MV Retired', 'active' => false]);
        Berth::create(['code' => 'B9', 'name' => 'Under repair', 'active' => false]);
        Customer::create(['name' => 'Dormant Ltd', 'active' => false]);
        Transporter::create(['name' => 'Closed Haulage', 'active' => false]);
        Truck::create(['plate' => 'KOLD 1X', 'active' => false]);
        Driver::create(['name' => 'Retired Driver', 'licence_number' => 'DL-OLD', 'active' => false]);

        // tank extremes
        Tank::where('code', 'PFAD-01')->update(['current_volume_kg' => 1_900_000]);                    // 95%
        Tank::create(['code' => 'FULL-1', 'name' => 'Full tank', 'commodity' => Commodity::CPO, 'capacity_kg' => 1_000, 'opening_volume_kg' => 1_000, 'current_volume_kg' => 1_000]);

        // a truck that has been here too long
        TruckVisit::whereHas('truck', fn ($q) => $q->where('plate', 'KDA 999Z'))->first()
            ->forceFill(['gate_in_at' => now()->subHours(13)])->saveQuietly();
    }

    /** One real record id per resource slug, for building concrete URLs. */
    protected function sampleIds(): array
    {
        return [
            'vessel-discharges' => VesselDischarge::first()->id,
            'delivery-orders' => DeliveryOrder::first()->id,
            'truck-visits' => TruckVisit::first()->id,
            'tanks' => Tank::first()->id,
            'correction-requests' => CorrectionRequest::first()->id,
            'users' => User::first()->id,
            'vessels' => Vessel::first()->id,
            'berths' => Berth::first()->id,
            'customers' => Customer::first()->id,
            'transporters' => Transporter::first()->id,
            'trucks' => Truck::first()->id,
            'drivers' => Driver::first()->id,
        ];
    }
}
