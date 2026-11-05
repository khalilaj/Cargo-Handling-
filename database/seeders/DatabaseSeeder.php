<?php

namespace Database\Seeders;

use App\Domain\Access\Role;
use App\Domain\Cargo\Actions\RecordTruckDelivery;
use App\Domain\Cargo\Actions\RecordVesselDischarge;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Corrections\Actions\RequestCorrection;
use App\Domain\Operations\Actions\RecordTruckLoad;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Models\CargoMovement;
use App\Models\CorrectionRequest;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\User;
use App\Models\VesselDischarge;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;

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
        $this->seedOperations();
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
        $mk = fn (array $a) => VesselDischarge::create($a);

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
