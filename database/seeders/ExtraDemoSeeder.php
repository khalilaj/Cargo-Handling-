<?php

namespace Database\Seeders;

use App\Domain\Access\Role;
use App\Domain\Cargo\Actions\RecordTruckDelivery;
use App\Domain\Cargo\Actions\RecordVesselDischarge;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Fleet\Actions\TruckVisitWorkflow;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Models\Berth;
use App\Models\CargoMovement;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Tank;
use App\Models\Transporter;
use App\Models\Truck;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDischarge;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * More demo data: a fuller yard with history, so the dashboard, reports and the two wizards have something to show.
 * Every barrel goes through the real ledger actions, so capacity and the 0.5% loss rule apply exactly as in live use.
 * Safe to run on every boot: the registers are matched by name or code, and the operations block runs once, atomically.
 */
class ExtraDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->seedTanksAndBerths();
            $this->seedRegisters();
            $this->seedOperations();
        });
    }

    private function seedTanksAndBerths(): void
    {
        foreach ([
            ['CPO-03', 'Crude Palm Oil 3', Commodity::CPO, 5_000_000],
            ['CPOL-03', 'Palm Olein 3', Commodity::CPOL, 3_000_000],
            ['PFAD-02', 'PFAD 2', Commodity::PFAD, 2_000_000],
        ] as [$code, $name, $c, $cap]) {
            Tank::updateOrCreate(['code' => $code], [
                'name' => $name, 'commodity' => $c, 'capacity_kg' => $cap, 'requires_heating' => $c->requiresHeating(),
            ]);
        }

        // Two free berths, so a scheduled ship can be berthed in the wizard.
        foreach ([['B3', 'Jetty 2, berth 1'], ['B4', 'Jetty 2, berth 2']] as [$code, $name]) {
            Berth::updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }

    private function seedRegisters(): void
    {
        foreach ([
            ['MV Lamu Express', '9402211', 'Kenya', 'Mombasa Shipping Agents'],
            ['MV Indian Ocean Spirit', '9356123', 'Singapore', 'Sumatra Maritime Agency'],
            ['MV Zanzibar Queen', '9288901', 'Tanzania', 'East Africa Marine'],
            ['MV Kilindini Star', null, 'Kenya', 'Mombasa Shipping Agents'],
        ] as [$name, $imo, $flag, $agent]) {
            $v = Vessel::firstOrNew(['name' => $name]);
            $v->fill(['imo' => $v->imo ?? $imo, 'flag' => $v->flag ?? $flag, 'owner_agent' => $v->owner_agent ?? $agent])->save();
        }

        foreach ([
            ['Pwani Oil Products', 'Amina Salim', '+254 700 000 105', 'orders@pwani.example.com'],
            ['Capwell Industries', 'Brian Mutua', '+254 700 000 106', 'logistics@capwell.example.com'],
            ['Golden Africa Foods', 'Lucy Njeri', '+254 700 000 107', 'dispatch@goldenafrica.example.com'],
            ['Rafiki Soap Works', 'Peter Kimani', '+254 700 000 108', 'supply@rafiki.example.com'],
        ] as [$name, $contact, $phone, $email]) {
            $c = Customer::firstOrNew(['name' => $name]);
            $c->fill(['contact_person' => $c->contact_person ?? $contact, 'phone' => $c->phone ?? $phone, 'email' => $c->email ?? $email])->save();
        }

        $savannah = Transporter::firstOrCreate(['name' => 'Savannah Bulk Haulers'], ['phone' => '+254 711 000 203']);
        $tsavo = Transporter::firstOrCreate(['name' => 'Tsavo Liquid Logistics'], ['phone' => '+254 711 000 204']);

        // Dates chosen to show every document state again: valid, expiring soon, expired, not recorded.
        foreach ([
            ['KDB 118Q', $savannah, 31_000, 14_200, '2027-05-10', '2027-04-02'],     // all valid
            ['KDC 702R', $tsavo, 30_000, 14_000, '2027-02-14', '2026-11-28'],        // inspection EXPIRING SOON
            ['KCZ 340T', $savannah, 29_000, 13_800, '2026-10-20', '2027-01-30'],     // insurance EXPIRED
            ['KDE 055V', $tsavo, 32_000, 15_000, '2027-08-01', '2027-08-01'],        // all valid
            ['KDF 913W', $savannah, 30_500, 14_400, null, null],                     // not recorded
            ['KCY 227U', $tsavo, 28_500, 13_600, '2027-03-18', '2027-03-18'],        // all valid
        ] as [$plate, $tr, $payload, $tare, $ins, $insp]) {
            Truck::firstOrCreate(['plate' => $plate], [
                'transporter_id' => $tr->id, 'capacity_kg' => $payload, 'tare_kg' => $tare,
                'insurance_expiry' => $ins, 'inspection_expiry' => $insp,
            ]);
        }

        foreach ([
            ['James Mwangi', 'DL-KE-102001', '2029-02-01', $savannah],     // valid
            ['Aisha Hassan', 'DL-KE-102118', '2026-12-05', $tsavo],        // expiring soon
            ['Hassan Ali', 'DL-KE-102290', '2027-09-12', $tsavo],          // valid
            ['Esther Chebet', 'DL-KE-102355', '2028-06-30', $savannah],    // valid
        ] as [$name, $licence, $expiry, $tr]) {
            Driver::firstOrCreate(['licence_number' => $licence], [
                'name' => $name, 'licence_expiry' => $expiry, 'transporter_id' => $tr->id, 'phone' => '+254 722 000 302',
            ]);
        }
    }

    /** Past voyages and orders with their real ledger movements, plus open ones to run through the wizards. */
    private function seedOperations(): void
    {
        // Already seeded, or the database holds one of these refs for another reason: leave the voyages alone
        // rather than crash the boot on the unique voyage_ref constraint.
        $refs = ['VOY-2610-01', 'VOY-2610-02', 'VOY-2611-06', 'VOY-2611-07', 'VOY-2611-08', 'VOY-2611-09'];
        if (VesselDischarge::whereIn('voyage_ref', $refs)->exists()) {
            return;
        }

        $in = app(RecordVesselDischarge::class);
        $out = app(RecordTruckDelivery::class);
        $d = fn (string $when) => Carbon::parse($when);
        $tank = fn (string $code) => Tank::where('code', $code)->value('id');
        $berth = fn (string $code) => Berth::where('code', $code)->value('id');
        $voyage = fn (array $a) => VesselDischarge::create($a + ['vessel_id' => Vessel::where('name', $a['vessel_name'])->value('id')]);
        $order = fn (array $a) => DeliveryOrder::create($a + ['customer_id' => Customer::where('name', $a['customer'])->value('id')]);
        $link = fn (string $key, array $fk) => CargoMovement::where('idempotency_key', $key)->update($fk);

        // ---- ships that have finished discharging: the bill of lading is fully received ----
        $v1 = $voyage(['voyage_ref' => 'VOY-2610-01', 'vessel_name' => 'MV Lamu Express', 'commodity' => Commodity::CPO,
            'bl_quantity_kg' => 3_000_000, 'status' => DischargeStatus::Completed, 'berth_id' => $berth('B3'),
            'eta' => '2026-10-27 05:00', 'berthed_at' => '2026-10-27 06:00', 'started_at' => '2026-10-27 06:30', 'completed_at' => '2026-10-27 19:00']);
        $in($tank('CPO-03'), 1_800_000, $d('2026-10-27 08:00'), 'xseed-in-1a', 'MV Lamu Express');
        $in($tank('CPO-03'), 1_200_000, $d('2026-10-27 15:00'), 'xseed-in-1b', 'MV Lamu Express');
        $link('xseed-in-1a', ['vessel_discharge_id' => $v1->id]);
        $link('xseed-in-1b', ['vessel_discharge_id' => $v1->id]);

        $v2 = $voyage(['voyage_ref' => 'VOY-2610-02', 'vessel_name' => 'MV Indian Ocean Spirit', 'commodity' => Commodity::CPOL,
            'bl_quantity_kg' => 2_000_000, 'status' => DischargeStatus::Completed, 'berth_id' => $berth('B4'),
            'eta' => '2026-10-30 08:00', 'berthed_at' => '2026-10-30 09:00', 'started_at' => '2026-10-30 09:30', 'completed_at' => '2026-10-30 20:00']);
        $in($tank('CPOL-03'), 2_000_000, $d('2026-10-30 11:00'), 'xseed-in-2', 'MV Indian Ocean Spirit');
        $link('xseed-in-2', ['vessel_discharge_id' => $v2->id]);

        $v3 = $voyage(['voyage_ref' => 'VOY-2611-06', 'vessel_name' => 'MV Zanzibar Queen', 'commodity' => Commodity::PFAD,
            'bl_quantity_kg' => 1_500_000, 'status' => DischargeStatus::Completed, 'berth_id' => $berth('B3'),
            'eta' => '2026-11-06 06:00', 'berthed_at' => '2026-11-06 06:45', 'started_at' => '2026-11-06 07:00', 'completed_at' => '2026-11-06 15:00']);
        $in($tank('PFAD-02'), 900_000, $d('2026-11-06 07:30'), 'xseed-in-3a', 'MV Zanzibar Queen');
        $in($tank('PFAD-02'), 600_000, $d('2026-11-06 12:00'), 'xseed-in-3b', 'MV Zanzibar Queen');
        $link('xseed-in-3a', ['vessel_discharge_id' => $v3->id]);
        $link('xseed-in-3b', ['vessel_discharge_id' => $v3->id]);

        // ---- a ship pumping into a tank at B3; B4 stays free so a scheduled ship can be berthed in the demo ----
        $alongside = fn (VesselDischarge $v) => $v->update(['active_berth_id' => $v->berth_id, 'active_vessel_id' => $v->vessel_id]);
        $v4 = $voyage(['voyage_ref' => 'VOY-2611-09', 'vessel_name' => 'MV Lamu Express', 'commodity' => Commodity::CPO,
            'bl_quantity_kg' => 3_200_000, 'status' => DischargeStatus::Discharging, 'berth_id' => $berth('B3'),
            'eta' => '2026-11-08 05:00', 'berthed_at' => '2026-11-08 06:00', 'started_at' => '2026-11-08 07:00']);
        $alongside($v4);
        $in($tank('CPO-02'), 1_100_000, $d('2026-11-08 09:00'), 'xseed-in-4a', 'MV Lamu Express');
        $link('xseed-in-4a', ['vessel_discharge_id' => $v4->id]);

        // ---- ships still to come: free berths B3 and B4 are waiting for them ----
        $voyage(['voyage_ref' => 'VOY-2611-07', 'vessel_name' => 'MV Kilindini Star', 'commodity' => Commodity::CPO,
            'bl_quantity_kg' => 2_500_000, 'status' => DischargeStatus::Scheduled, 'eta' => '2026-11-10 10:00']);
        $voyage(['voyage_ref' => 'VOY-2611-08', 'vessel_name' => 'MV Indian Ocean Spirit', 'commodity' => Commodity::CPOL,
            'bl_quantity_kg' => 1_800_000, 'status' => DischargeStatus::Scheduled, 'eta' => '2026-11-12 06:00']);

        // ---- delivered orders, each with the truck that took the oil ----
        foreach ([
            ['ORD-1005', 'Pwani Oil Products', Commodity::CPO, 450_000, 'CPO-03', '2026-10-29 07:00', '2026-10-29 07:30', '2026-10-29 11:00', '2026-10-29 09:00', 'KDB 118Q'],
            ['ORD-1006', 'Capwell Industries', Commodity::CPOL, 300_000, 'CPOL-03', '2026-10-31 08:00', '2026-10-31 08:30', '2026-10-31 12:00', '2026-10-31 10:00', 'KDC 702R'],
            ['ORD-1007', 'Golden Africa Foods', Commodity::CPO, 600_000, 'CPO-03', '2026-11-04 09:00', '2026-11-04 09:30', '2026-11-04 13:00', '2026-11-04 10:30', 'KDE 055V'],
            ['ORD-1008', 'Rafiki Soap Works', Commodity::PFAD, 250_000, 'PFAD-02', '2026-11-07 08:00', '2026-11-07 08:30', '2026-11-07 11:00', '2026-11-07 09:30', 'KDF 913W'],
        ] as $n => [$ref, $customer, $commodity, $kg, $from, $scheduled, $started, $completed, $loaded, $plate]) {
            $o = $order(['order_ref' => $ref, 'customer' => $customer, 'commodity' => $commodity, 'ordered_kg' => $kg,
                'status' => OrderStatus::Completed, 'scheduled_at' => $scheduled, 'started_at' => $started, 'completed_at' => $completed]);
            $out($tank($from), $kg, $d($loaded), 'xseed-out-'.($n + 1), $plate);
            $link('xseed-out-'.($n + 1), ['delivery_order_id' => $o->id]);
        }

        // ---- orders waiting to be opened, for the delivery wizard ----
        $order(['order_ref' => 'ORD-1009', 'customer' => 'Pwani Oil Products', 'commodity' => Commodity::CPOL, 'ordered_kg' => 500_000,
            'status' => OrderStatus::Pending, 'scheduled_at' => '2026-11-09 08:00']);
        $loading = $order(['order_ref' => 'ORD-1011', 'customer' => 'Golden Africa Foods', 'commodity' => Commodity::CPO, 'ordered_kg' => 400_000,
            'status' => OrderStatus::Loading, 'scheduled_at' => '2026-11-08 08:00', 'started_at' => '2026-11-08 08:30']);
        $order(['order_ref' => 'ORD-1010', 'customer' => 'Capwell Industries', 'commodity' => Commodity::CPO, 'ordered_kg' => 350_000,
            'status' => OrderStatus::Pending, 'scheduled_at' => '2026-11-10 09:00']);
        $this->seedTrucksOnSite($loading);
    }

    /** Four more trucks at the gate, one at each stage, through the real workflow. Nothing is booked out of a tank. */
    private function seedTrucksOnSite(DeliveryOrder $loading): void
    {
        $operator = User::where('role', Role::Operator->value)->first();
        if (! $operator) {
            return;
        }

        $flow = app(TruckVisitWorkflow::class);
        $truck = fn (string $plate) => Truck::where('plate', $plate)->firstOrFail();
        $order = fn (string $ref) => DeliveryOrder::where('order_ref', $ref)->firstOrFail();

        auth()->setUser($operator);
        try {
            $flow->gateIn($order('ORD-1009'), $truck('KDB 118Q'));                                   // just arrived
            $flow->weighIn($flow->gateIn($order('ORD-1010'), $truck('KDE 055V')), 14_800);            // weighed in, waiting for its order
            $flow->startLoading($flow->weighIn($flow->gateIn($loading, $truck('KCY 227U')), 13_400)); // on the loading bay
            $flow->gateIn($loading, $truck('KDC 702R'));                                            // second truck queued for the same order
        } finally {
            auth()->forgetGuards();
        }
    }
}
