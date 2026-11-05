<?php

namespace Database\Seeders;

use App\Domain\Access\Role;
use App\Domain\Cargo\Actions\RecordTruckDelivery;
use App\Domain\Cargo\Actions\RecordVesselDischarge;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Corrections\Actions\RequestCorrection;
use App\Domain\Fleet\Actions\TruckVisitWorkflow;
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
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Idempotent: safe to run on every boot, and to upgrade an older demo database in place. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // One demo account per role; all share ADMIN_PASSWORD (change it in production).
        foreach ([
            ['admin', 'Terminal Admin', Role::Admin],
            ['supervisor', 'Shift Supervisor', Role::Supervisor],
            ['operator', 'Terminal Operator', Role::Operator],
            ['auditor', 'Internal Auditor', Role::Auditor],
        ] as [$handle, $name, $role]) {
            $pw = env('ADMIN_PASSWORD', 'password');
            $user = User::firstOrNew(['email' => $handle === 'admin' ? env('ADMIN_EMAIL', 'admin@cargo.test') : "{$handle}@cargo.test"]);
            $user->name = $name;
            $user->role = $role;
            if (! $user->exists || ! Hash::check($pw, $user->password)) {   // only touch the password when it really changed
                $user->password = $pw;
            }
            $user->save();
        }

        foreach ([
            ['CPO-01', 'Crude Palm Oil 1', Commodity::CPO, 5_000_000],
            ['CPO-02', 'Crude Palm Oil 2', Commodity::CPO, 5_000_000],
            ['CPOL-01', 'Palm Olein 1', Commodity::CPOL, 3_000_000],
            ['CPOL-02', 'Palm Olein 2', Commodity::CPOL, 3_000_000],
            ['PFAD-01', 'PFAD 1', Commodity::PFAD, 2_000_000],
        ] as [$code, $name, $c, $cap]) {
            Tank::updateOrCreate(['code' => $code], [
                'name' => $name, 'commodity' => $c, 'capacity_kg' => $cap, 'requires_heating' => $c->requiresHeating(),
            ]);
        }

        $this->seedLedger();
        $this->seedRegisters();
        $this->seedOperations();
        $this->linkOperationsToRegisters();
        $this->seedTruckVisits();
        $this->seedCorrection();
    }

    private function seedLedger(): void
    {
        if (CargoMovement::exists()) {
            return;
        }

        $in = app(RecordVesselDischarge::class);
        $out = app(RecordTruckDelivery::class);
        $d = fn (string $when) => Carbon::parse($when);
        $id = fn (string $code) => Tank::where('code', $code)->value('id');

        $in($id('CPO-01'), 4_200_000, $d('2026-11-02 06:30'), 'seed-1', 'MV Pacific Dawn');
        $in($id('CPOL-01'), 2_500_000, $d('2026-11-02 14:00'), 'seed-2', 'MV Sumatra Star');
        $in($id('PFAD-01'), 1_700_000, $d('2026-11-03 09:15'), 'seed-3', 'MV Borneo Trader');
        $out($id('CPO-01'), 350_000, $d('2026-11-03 08:00'), 'seed-4', 'KDA 214X');
        $out($id('CPOL-01'), 600_000, $d('2026-11-03 11:30'), 'seed-5', 'KCB 887L');
    }

    /**
     * Demo registers. updateOrCreate by name: on the live database these entries were already
     * created from the typed names by the migration backfill, so this only fills in the details.
     */
    private function seedRegisters(): void
    {
        foreach ([['B1', 'Jetty 1, berth 1'], ['B2', 'Jetty 1, berth 2']] as [$code, $name]) {
            Berth::updateOrCreate(['code' => $code], ['name' => $name]);
        }

        foreach ([
            ['MV Pacific Dawn', '9301122', 'Panama', 'Pacific Bulk Shipping'],
            ['MV Sumatra Star', '9215533', 'Singapore', 'Sumatra Maritime Agency'],
            ['MV Borneo Trader', '9188744', 'Malaysia', 'Borneo Lines'],
            ['MV Kenya Star', '9377855', 'Liberia', 'Mombasa Shipping Agents'],
            ['MV Mombasa Pearl', null, 'Kenya', 'Mombasa Shipping Agents'],
        ] as [$name, $imo, $flag, $agent]) {
            $v = Vessel::firstOrNew(['name' => $name]);
            $v->fill(['imo' => $v->imo ?? $imo, 'flag' => $v->flag ?? $flag, 'owner_agent' => $v->owner_agent ?? $agent])->save();
        }

        foreach ([
            ['Kapa Oil Refineries', 'Grace Muthoni', '+254 700 000 101', 'orders@kapa.example.com'],
            ['Bidco Africa', 'Daniel Otieno', '+254 700 000 102', 'logistics@bidco.example.com'],
            ['Unga Group', 'Faith Wambui', '+254 700 000 103', 'dispatch@unga.example.com'],
            ['Menengai Oils', 'Samuel Kiprop', '+254 700 000 104', 'supply@menengai.example.com'],
        ] as [$name, $contact, $phone, $email]) {
            $c = Customer::firstOrNew(['name' => $name]);
            $c->fill(['contact_person' => $c->contact_person ?? $contact, 'phone' => $c->phone ?? $phone, 'email' => $c->email ?? $email])->save();
        }

        $rift = Transporter::firstOrCreate(['name' => 'Rift Haulage Ltd'], ['phone' => '+254 711 000 201']);
        $coast = Transporter::firstOrCreate(['name' => 'Coast Tankers Ltd'], ['phone' => '+254 711 000 202']);

        // Dates chosen to show every document state: expired, expiring soon, valid, not recorded.
        foreach ([
            ['KDA 214X', $rift, 30_000, 14_000, '2026-11-02', '2027-03-01'],     // insurance EXPIRED
            ['KCB 887L', $coast, 32_000, 15_000, '2027-01-15', '2026-11-20'],    // inspection EXPIRING SOON
            ['KCD 452M', $rift, 30_000, 14_500, '2027-06-30', '2027-06-30'],     // all valid
            ['KDA 999Z', $coast, 28_000, 13_500, null, null],                    // not recorded
        ] as [$plate, $tr, $payload, $tare, $ins, $insp]) {
            Truck::firstOrCreate(['plate' => $plate], [
                'transporter_id' => $tr->id, 'capacity_kg' => $payload, 'tare_kg' => $tare,
                'insurance_expiry' => $ins, 'inspection_expiry' => $insp,
            ]);
        }

        foreach ([
            ['Joseph Kamau', 'DL-KE-100234', '2028-04-30', $rift],      // valid
            ['Mary Wanjiru', 'DL-KE-100877', '2026-11-25', $coast],     // expiring soon
            ['Peter Otieno', 'DL-KE-101456', '2026-10-01', $rift],      // EXPIRED
        ] as [$name, $licence, $expiry, $tr]) {
            Driver::firstOrCreate(['licence_number' => $licence], [
                'name' => $name, 'licence_expiry' => $expiry, 'transporter_id' => $tr->id, 'phone' => '+254 722 000 301',
            ]);
        }
    }

    /** Link voyages and orders to the registers and give the ships alongside a berth. Idempotent, no audit noise. */
    private function linkOperationsToRegisters(): void
    {
        foreach (Vessel::all() as $v) {
            VesselDischarge::whereNull('vessel_id')->where('vessel_name', $v->name)->update(['vessel_id' => $v->id]);
        }
        foreach (Customer::all() as $c) {
            DeliveryOrder::whereNull('customer_id')->where('customer', $c->name)->update(['customer_id' => $c->id]);
        }

        foreach (['VOY-2611-03' => 'B1', 'VOY-2611-04' => 'B2'] as $voyage => $berth) {
            $id = Berth::where('code', $berth)->value('id');
            VesselDischarge::where('voyage_ref', $voyage)->whereNull('berth_id')
                ->whereIn('status', [DischargeStatus::Berthed->value, DischargeStatus::Discharging->value])
                ->update(['berth_id' => $id, 'active_berth_id' => $id, 'active_vessel_id' => DB::raw('vessel_id')]);
        }
    }

    /**
     * Three trucks on site, at different stages, so the gate and the dashboard have something to show.
     * Nothing is booked out of a tank here, so stock and order figures are unchanged.
     */
    private function seedTruckVisits(): void
    {
        if (TruckVisit::exists()) {
            return;
        }

        $operator = User::where('role', Role::Operator->value)->first();
        $flow = app(TruckVisitWorkflow::class);
        $truck = fn (string $plate) => Truck::where('plate', $plate)->first();
        $order = fn (string $ref) => DeliveryOrder::where('order_ref', $ref)->first();

        auth()->setUser($operator);
        try {
            // Loading bay: a clean truck, ready for cargo.
            $v1 = $flow->gateIn($order('ORD-1003'), $truck('KCD 452M'), Driver::where('name', 'Joseph Kamau')->first());
            $flow->startLoading($flow->weighIn($v1, 14_500));

            // Waiting at the gate: inspection due soon, driver licence due soon.
            $flow->gateIn($order('ORD-1003'), $truck('KCB 887L'), Driver::where('name', 'Mary Wanjiru')->first());

            // Weighed in, waiting for its order to start: no document dates recorded.
            $v3 = $flow->gateIn($order('ORD-1004'), $truck('KDA 999Z'));
            $flow->weighIn($v3, 13_500);
        } finally {
            auth()->forgetGuards();
        }
    }

    /** One open correction so the approvals queue has something to show (filed by the operator, as in real life). */
    private function seedCorrection(): void
    {
        $operator = User::where('role', Role::Operator->value)->first();
        $movement = CargoMovement::where('idempotency_key', 'seed-5')->first();

        if (! $operator || ! $movement || CorrectionRequest::exists()) {
            return;
        }

        auth()->setUser($operator);          // so the audit log names the operator, not "system"
        try {
            app(RequestCorrection::class)($movement, $operator,
                'Weighbridge ticket shows 580,000 kg, not 600,000. I keyed the wrong figure.');
        } finally {
            auth()->forgetGuards();
        }
    }

    /** Wraps the demo movements in vessel / order records, and adds a few open ones. */
    private function seedOperations(): void
    {
        if (VesselDischarge::exists() || DeliveryOrder::exists()) {
            return;
        }

        $link = fn (string $key, array $fk) => CargoMovement::where('idempotency_key', $key)->update($fk);
        $mk = fn (array $a) => VesselDischarge::create($a + ['vessel_id' => Vessel::where('name', $a['vessel_name'])->value('id')]);

        $v1 = $mk(['voyage_ref' => 'VOY-2611-01', 'vessel_name' => 'MV Pacific Dawn', 'commodity' => Commodity::CPO,
            'bl_quantity_kg' => 4_200_000, 'status' => DischargeStatus::Completed, 'eta' => '2026-11-02 04:00',
            'berthed_at' => '2026-11-02 05:00', 'started_at' => '2026-11-02 06:00', 'completed_at' => '2026-11-02 18:00']);
        $v2 = $mk(['voyage_ref' => 'VOY-2611-02', 'vessel_name' => 'MV Sumatra Star', 'commodity' => Commodity::CPOL,
            'bl_quantity_kg' => 2_500_000, 'status' => DischargeStatus::Completed, 'eta' => '2026-11-02 12:00',
            'berthed_at' => '2026-11-02 13:00', 'started_at' => '2026-11-02 13:30', 'completed_at' => '2026-11-02 22:00']);
        $v3 = $mk(['voyage_ref' => 'VOY-2611-03', 'vessel_name' => 'MV Borneo Trader', 'commodity' => Commodity::PFAD,
            'bl_quantity_kg' => 2_000_000, 'status' => DischargeStatus::Discharging, 'eta' => '2026-11-03 08:00',
            'berthed_at' => '2026-11-03 08:30', 'started_at' => '2026-11-03 09:00']);
        $mk(['voyage_ref' => 'VOY-2611-04', 'vessel_name' => 'MV Kenya Star', 'commodity' => Commodity::CPO,
            'bl_quantity_kg' => 3_000_000, 'status' => DischargeStatus::Berthed, 'eta' => '2026-11-05 06:00',
            'berthed_at' => '2026-11-05 07:30']);
        $mk(['voyage_ref' => 'VOY-2611-05', 'vessel_name' => 'MV Mombasa Pearl', 'commodity' => Commodity::CPOL,
            'bl_quantity_kg' => 2_200_000, 'status' => DischargeStatus::Scheduled, 'eta' => now()->addDays(2)->setTime(9, 0)]);

        $link('seed-1', ['vessel_discharge_id' => $v1->id]);
        $link('seed-2', ['vessel_discharge_id' => $v2->id]);
        $link('seed-3', ['vessel_discharge_id' => $v3->id]);

        $o1 = DeliveryOrder::create(['order_ref' => 'ORD-1001', 'customer' => 'Kapa Oil Refineries', 'commodity' => Commodity::CPO,
            'ordered_kg' => 350_000, 'status' => OrderStatus::Completed, 'scheduled_at' => '2026-11-03 07:00',
            'started_at' => '2026-11-03 07:30', 'completed_at' => '2026-11-03 09:00']);
        $o2 = DeliveryOrder::create(['order_ref' => 'ORD-1002', 'customer' => 'Bidco Africa', 'commodity' => Commodity::CPOL,
            'ordered_kg' => 600_000, 'status' => OrderStatus::Completed, 'scheduled_at' => '2026-11-03 10:00',
            'started_at' => '2026-11-03 10:30', 'completed_at' => '2026-11-03 13:00']);
        $o3 = DeliveryOrder::create(['order_ref' => 'ORD-1003', 'customer' => 'Unga Group', 'commodity' => Commodity::CPOL,
            'ordered_kg' => 400_000, 'status' => OrderStatus::Loading, 'scheduled_at' => '2026-11-04 09:00',
            'started_at' => '2026-11-04 09:30']);
        DeliveryOrder::create(['order_ref' => 'ORD-1004', 'customer' => 'Menengai Oils', 'commodity' => Commodity::PFAD,
            'ordered_kg' => 200_000, 'status' => OrderStatus::Pending, 'scheduled_at' => now()->addDay()->setTime(8, 0)]);

        $link('seed-4', ['delivery_order_id' => $o1->id]);
        $link('seed-5', ['delivery_order_id' => $o2->id]);

        // An order part-way through loading, through the real domain action.
        app(RecordTruckLoad::class)(
            $o3, Tank::where('code', 'CPOL-01')->value('id'), 150_000,
            Carbon::parse('2026-11-04 10:15'), 'seed-6', 'KCD 452M',
        );
    }
}
