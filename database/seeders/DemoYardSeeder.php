<?php

namespace Database\Seeders;

use App\Domain\Access\Role;
use App\Domain\Cargo\Actions\RecordTruckDelivery;
use App\Domain\Cargo\Actions\RecordVesselDischarge;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Corrections\Actions\RequestCorrection;
use App\Domain\Fleet\Actions\TruckVisitWorkflow;
use App\Domain\Operations\Actions\RecordDischargeIntoTank;
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
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDischarge;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The live demo yard: three tanks (one per product), three berths, two ships alongside and two on the way,
 * five trucks on site, with history behind them. Every barrel goes through the real ledger actions, so
 * capacity and the 0.5% transit loss apply exactly as in live use.
 *
 * Safe on every boot: registers are matched by name, code or plate, and the operations are booked once,
 * in one transaction, only when none of the demo references exist yet.
 */
class DemoYardSeeder extends Seeder
{
    private const VOYAGE_REFS = ['VOY-2611-11', 'VOY-2611-12', 'VOY-2611-13', 'VOY-2611-14', 'VOY-2611-15', 'VOY-2611-16', 'VOY-2611-17', 'VOY-2611-18'];

    private const ORDER_REFS = ['ORD-2001', 'ORD-2002', 'ORD-2003', 'ORD-2004', 'ORD-2005', 'ORD-2006', 'ORD-2007', 'ORD-2008', 'ORD-2009'];

    public function run(): void
    {
        $this->seedTanksAndBerths();
        $this->seedRegisters();

        // Already seeded, or the database holds one of these refs for another reason: leave the operations alone
        // rather than crash the boot on a unique constraint.
        if (VesselDischarge::whereIn('voyage_ref', self::VOYAGE_REFS)->exists() || DeliveryOrder::whereIn('order_ref', self::ORDER_REFS)->exists()) {
            return;
        }

        try {
            DB::transaction(function () {
                $this->seedHistory();
                $this->seedShipsInPort();
                $this->seedOpenOrdersAndTrucks();
                $this->seedCorrection();
            });
        } catch (Throwable $e) {
            // Typically an older demo database whose tanks already hold stock. Nothing was written.
            Log::warning('Demo yard operations skipped: '.$e->getMessage());
        }
    }

    private function seedTanksAndBerths(): void
    {
        foreach ([
            ['CPO-01', 'Crude Palm Oil', Commodity::CPO, 5_000_000],
            ['CPOL-01', 'Palm Olein', Commodity::CPOL, 3_000_000],
            ['PFAD-01', 'PFAD', Commodity::PFAD, 2_000_000],
        ] as [$code, $name, $c, $cap]) {
            Tank::updateOrCreate(['code' => $code], [
                'name' => $name, 'commodity' => $c, 'capacity_kg' => $cap, 'requires_heating' => $c->requiresHeating(),
            ]);
        }

        foreach ([['B1', 'Jetty 1, berth 1'], ['B2', 'Jetty 1, berth 2'], ['B3', 'Jetty 2, berth 1']] as [$code, $name]) {
            Berth::updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }

    private function seedRegisters(): void
    {
        foreach ([
            ['MV Lamu Express', '9402211', 'Kenya', 'Mombasa Shipping Agents'],
            ['MV Pacific Dawn', '9301122', 'Panama', 'Pacific Bulk Shipping'],
            ['MV Sumatra Star', '9215533', 'Singapore', 'Sumatra Maritime Agency'],
            ['MV Zanzibar Queen', '9288901', 'Tanzania', 'East Africa Marine'],
            ['MV Indian Ocean Spirit', '9356123', 'Singapore', 'Sumatra Maritime Agency'],
            ['MV Kilindini Star', '9411236', 'Liberia', 'Mombasa Shipping Agents'],
        ] as [$name, $imo, $flag, $agent]) {
            $v = Vessel::firstOrNew(['name' => $name]);
            $imoTaken = $imo !== null && Vessel::where('imo', $imo)->where('name', '!=', $name)->exists();   // imo is unique
            $v->fill(['imo' => $v->imo ?? ($imoTaken ? null : $imo), 'flag' => $v->flag ?? $flag, 'owner_agent' => $v->owner_agent ?? $agent])->save();
        }

        foreach ([
            ['Kapa Oil Refineries', 'Grace Muthoni', '+254 700 000 101', 'orders@kapa.example.com'],
            ['Bidco Africa', 'Daniel Otieno', '+254 700 000 102', 'logistics@bidco.example.com'],
            ['Unga Group', 'Faith Wambui', '+254 700 000 103', 'dispatch@unga.example.com'],
            ['Menengai Oils', 'Samuel Kiprop', '+254 700 000 104', 'supply@menengai.example.com'],
            ['Pwani Oil Products', 'Amina Salim', '+254 700 000 105', 'orders@pwani.example.com'],
        ] as [$name, $contact, $phone, $email]) {
            $c = Customer::firstOrNew(['name' => $name]);
            $c->fill(['contact_person' => $c->contact_person ?? $contact, 'phone' => $c->phone ?? $phone, 'email' => $c->email ?? $email])->save();
        }

        $rift = Transporter::firstOrCreate(['name' => 'Rift Haulage Ltd'], ['phone' => '+254 711 000 201']);
        $coast = Transporter::firstOrCreate(['name' => 'Coast Tankers Ltd'], ['phone' => '+254 711 000 202']);

        // Document dates are relative to today so the four states never go stale.
        $valid = now()->addMonths(8)->toDateString();
        $soon = now()->addDays(12)->toDateString();
        $expired = now()->subDays(20)->toDateString();

        foreach ([
            ['KDB 118Q', $rift, 31_000, 14_200, $valid, $valid],         // all valid
            ['KDC 702R', $coast, 30_000, 14_000, $valid, $soon],         // inspection EXPIRING SOON
            ['KCZ 340T', $rift, 29_000, 13_800, $expired, $valid],       // insurance EXPIRED
            ['KDE 055V', $coast, 32_000, 15_000, $valid, $valid],        // all valid
            ['KDF 913W', $rift, 30_500, 14_400, null, null],             // not recorded
            ['KCY 227U', $coast, 28_500, 13_600, $valid, $valid],        // all valid
            ['KDG 481B', $rift, 30_000, 14_100, $soon, $valid],          // insurance EXPIRING SOON
            ['KDH 266C', $coast, 31_500, 14_800, $valid, $expired],      // inspection EXPIRED
        ] as [$plate, $tr, $payload, $tare, $ins, $insp]) {
            Truck::firstOrCreate(['plate' => $plate], [
                'transporter_id' => $tr->id, 'capacity_kg' => $payload, 'tare_kg' => $tare,
                'insurance_expiry' => $ins, 'inspection_expiry' => $insp,
            ]);
        }

        foreach ([
            ['Joseph Kamau', 'DL-KE-100234', $valid, $rift],
            ['Mary Wanjiru', 'DL-KE-100877', $soon, $coast],         // expiring soon
            ['Peter Otieno', 'DL-KE-101456', $expired, $rift],       // EXPIRED
            ['James Mwangi', 'DL-KE-102001', $valid, $rift],
            ['Hassan Ali', 'DL-KE-102290', $valid, $coast],
            ['Esther Chebet', 'DL-KE-102355', $valid, $coast],
        ] as [$name, $licence, $expiry, $tr]) {
            Driver::firstOrCreate(['licence_number' => $licence], [
                'name' => $name, 'licence_expiry' => $expiry, 'transporter_id' => $tr->id, 'phone' => '+254 722 000 301',
            ]);
        }
    }

    private function tank(string $code): int
    {
        return (int) Tank::where('code', $code)->value('id');
    }

    private function voyage(array $a): VesselDischarge
    {
        $berth = $a['berth'] ?? null;
        unset($a['berth']);

        return VesselDischarge::create($a + [
            'vessel_id' => Vessel::where('name', $a['vessel_name'])->value('id'),
            'berth_id' => $berth ? Berth::where('code', $berth)->value('id') : null,
        ]);
    }

    private function order(array $a): DeliveryOrder
    {
        return DeliveryOrder::create($a + ['customer_id' => Customer::where('name', $a['customer'])->value('id')]);
    }

    private function link(string $key, array $fk): void
    {
        CargoMovement::where('idempotency_key', $key)->update($fk);
    }

    /** Finished ships and delivered orders, on fixed past dates. */
    private function seedHistory(): void
    {
        $in = app(RecordVesselDischarge::class);
        $out = app(RecordTruckDelivery::class);

        // Ships: ref, vessel, commodity, bill of lading kg, berth, tank, eta, berthed, started, completed, [[kg, when], ...]
        foreach ([
            ['VOY-2611-11', 'MV Lamu Express', Commodity::CPO, 2_000_000, 'B1', 'CPO-01', '2026-10-20 05:00', '2026-10-20 06:00', '2026-10-20 06:30', '2026-10-20 19:00',
                [[1_200_000, '2026-10-20 10:00'], [800_000, '2026-10-20 16:00']]],
            ['VOY-2611-12', 'MV Pacific Dawn', Commodity::CPO, 1_000_000, 'B2', 'CPO-01', '2026-10-27 07:00', '2026-10-27 08:00', '2026-10-27 08:30', '2026-10-27 16:00',
                [[1_000_000, '2026-10-27 12:00']]],
            ['VOY-2611-13', 'MV Sumatra Star', Commodity::CPOL, 2_000_000, 'B3', 'CPOL-01', '2026-10-24 06:00', '2026-10-24 07:00', '2026-10-24 07:30', '2026-10-24 20:00',
                [[1_200_000, '2026-10-24 11:00'], [800_000, '2026-10-24 17:00']]],
            ['VOY-2611-14', 'MV Zanzibar Queen', Commodity::PFAD, 1_000_000, 'B1', 'PFAD-01', '2026-11-02 06:00', '2026-11-02 06:45', '2026-11-02 07:00', '2026-11-02 15:00',
                [[600_000, '2026-11-02 09:00'], [400_000, '2026-11-02 13:00']]],
        ] as $n => [$ref, $vessel, $commodity, $bl, $berth, $tank, $eta, $berthed, $started, $completed, $parts]) {
            $v = $this->voyage(['voyage_ref' => $ref, 'vessel_name' => $vessel, 'commodity' => $commodity, 'bl_quantity_kg' => $bl,
                'status' => DischargeStatus::Completed, 'berth' => $berth, 'eta' => $eta, 'berthed_at' => $berthed,
                'started_at' => $started, 'completed_at' => $completed]);
            foreach ($parts as $i => [$kg, $when]) {
                $key = 'dy-in-'.($n + 1).'-'.($i + 1);
                $in($this->tank($tank), $kg, Carbon::parse($when), $key, $vessel);
                $this->link($key, ['vessel_discharge_id' => $v->id]);
            }
        }

        // Orders: ref, customer, commodity, kg, tank, scheduled, started, completed, loaded at, plate
        foreach ([
            ['ORD-2001', 'Kapa Oil Refineries', Commodity::CPO, 450_000, 'CPO-01', '2026-10-23 07:00', '2026-10-23 07:30', '2026-10-23 11:00', '2026-10-23 09:00', 'KDB 118Q'],
            ['ORD-2002', 'Bidco Africa', Commodity::CPOL, 350_000, 'CPOL-01', '2026-10-28 08:00', '2026-10-28 08:30', '2026-10-28 12:00', '2026-10-28 10:00', 'KCZ 340T'],
            ['ORD-2003', 'Unga Group', Commodity::CPO, 600_000, 'CPO-01', '2026-10-30 09:00', '2026-10-30 09:30', '2026-10-30 13:00', '2026-10-30 10:30', 'KDE 055V'],
            ['ORD-2004', 'Menengai Oils', Commodity::PFAD, 250_000, 'PFAD-01', '2026-11-06 08:00', '2026-11-06 08:30', '2026-11-06 11:00', '2026-11-06 09:30', 'KDG 481B'],
            ['ORD-2005', 'Pwani Oil Products', Commodity::CPOL, 300_000, 'CPOL-01', '2026-11-05 08:00', '2026-11-05 08:30', '2026-11-05 12:00', '2026-11-05 10:00', 'KDH 266C'],
            ['ORD-2006', 'Kapa Oil Refineries', Commodity::CPO, 300_000, 'CPO-01', '2026-11-04 09:00', '2026-11-04 09:30', '2026-11-04 13:00', '2026-11-04 10:30', 'KDC 702R'],
        ] as $n => [$ref, $customer, $commodity, $kg, $tank, $scheduled, $started, $completed, $loaded, $plate]) {
            $o = $this->order(['order_ref' => $ref, 'customer' => $customer, 'commodity' => $commodity, 'ordered_kg' => $kg,
                'status' => OrderStatus::Completed, 'scheduled_at' => $scheduled, 'started_at' => $started, 'completed_at' => $completed]);
            $key = 'dy-out-'.($n + 1);
            $out($this->tank($tank), $kg, Carbon::parse($loaded), $key, $plate);
            $this->link($key, ['delivery_order_id' => $o->id]);
        }
    }

    /** Two ships alongside (B1 pumping, B2 waiting, B3 free) and two still to come. */
    private function seedShipsInPort(): void
    {
        $v = $this->voyage(['voyage_ref' => 'VOY-2611-15', 'vessel_name' => 'MV Indian Ocean Spirit', 'commodity' => Commodity::CPO,
            'bl_quantity_kg' => 2_000_000, 'status' => DischargeStatus::Discharging, 'berth' => 'B1',
            'eta' => now()->subHours(30), 'berthed_at' => now()->subHours(26), 'started_at' => now()->subHours(24)]);
        $pump = app(RecordDischargeIntoTank::class);
        $pump($v, $this->tank('CPO-01'), 500_000, now()->subHours(18), 'dy-in-5-1');
        $pump($v, $this->tank('CPO-01'), 300_000, now()->subHours(6), 'dy-in-5-2');

        $this->voyage(['voyage_ref' => 'VOY-2611-16', 'vessel_name' => 'MV Kilindini Star', 'commodity' => Commodity::PFAD,
            'bl_quantity_kg' => 800_000, 'status' => DischargeStatus::Berthed, 'berth' => 'B2',
            'eta' => now()->subHours(5), 'berthed_at' => now()->subHours(3)]);

        $this->voyage(['voyage_ref' => 'VOY-2611-17', 'vessel_name' => 'MV Pacific Dawn', 'commodity' => Commodity::CPOL,
            'bl_quantity_kg' => 1_200_000, 'status' => DischargeStatus::Scheduled, 'eta' => now()->addDays(2)->setTime(9, 0)]);
        $this->voyage(['voyage_ref' => 'VOY-2611-18', 'vessel_name' => 'MV Lamu Express', 'commodity' => Commodity::CPO,
            'bl_quantity_kg' => 1_200_000, 'status' => DischargeStatus::Scheduled, 'eta' => now()->addDays(5)->setTime(14, 0)]);
    }

    /** One loading order with three trucks, two pending orders with one truck each; all through the real workflow. */
    private function seedOpenOrdersAndTrucks(): void
    {
        $loading = $this->order(['order_ref' => 'ORD-2007', 'customer' => 'Unga Group', 'commodity' => Commodity::CPO, 'ordered_kg' => 400_000,
            'status' => OrderStatus::Loading, 'scheduled_at' => now()->subHours(5), 'started_at' => now()->subHours(4)]);
        $cpol = $this->order(['order_ref' => 'ORD-2008', 'customer' => 'Bidco Africa', 'commodity' => Commodity::CPOL, 'ordered_kg' => 500_000,
            'status' => OrderStatus::Pending, 'scheduled_at' => now()->addHours(2)]);
        $pfad = $this->order(['order_ref' => 'ORD-2009', 'customer' => 'Menengai Oils', 'commodity' => Commodity::PFAD, 'ordered_kg' => 300_000,
            'status' => OrderStatus::Pending, 'scheduled_at' => now()->addDay()->setTime(8, 0)]);

        $operator = User::where('role', Role::Operator->value)->first();
        $flow = app(TruckVisitWorkflow::class);
        $load = app(RecordTruckLoad::class);
        $truck = fn (string $plate) => Truck::where('plate', $plate)->firstOrFail();
        $driver = fn (string $name) => Driver::where('name', $name)->firstOrFail();
        $cpo = $this->tank('CPO-01');

        // The workflow stamps "now", so step the clock back to when each thing happened.
        $at = function (int $minutesAgo, callable $do) {
            $previous = Carbon::getTestNow();
            Carbon::setTestNow(now()->subMinutes($minutesAgo));
            try {
                return $do();
            } finally {
                Carbon::setTestNow($previous);
            }
        };

        if ($operator) {
            auth()->setUser($operator);          // so the audit log names the operator
        }
        try {
            // Weighed out: full load booked, waiting at the gate to leave.
            $t1 = $at(200, fn () => $flow->gateIn($loading, $truck('KDE 055V'), $driver('James Mwangi')));
            $t1 = $at(190, fn () => $flow->weighIn($t1, 15_000));
            $t1 = $at(170, fn () => $flow->startLoading($t1));
            $at(150, fn () => $load($loading, $cpo, 30_000, now(), 'dy-load-1', null, $t1));
            $at(120, fn () => $flow->weighOut($t1, 45_000));

            // On the loading bay, part loaded.
            $t2 = $at(110, fn () => $flow->gateIn($loading, $truck('KDB 118Q'), $driver('Joseph Kamau')));
            $t2 = $at(100, fn () => $flow->weighIn($t2, 14_200));
            $t2 = $at(60, fn () => $flow->startLoading($t2));
            $at(30, fn () => $load($loading, $cpo, 18_000, now(), 'dy-load-2', null, $t2));

            // Weighed in, waiting for its order to start.
            $t4 = $at(45, fn () => $flow->gateIn($pfad, $truck('KDF 913W')));
            $at(35, fn () => $flow->weighIn($t4, 14_400));

            // Just arrived, and queued behind the loading bay.
            $at(25, fn () => $flow->gateIn($loading, $truck('KDC 702R'), $driver('Mary Wanjiru')));
            $at(10, fn () => $flow->gateIn($cpol, $truck('KCY 227U'), $driver('Hassan Ali')));
        } finally {
            auth()->forgetGuards();
        }
    }

    /** One open correction so the approvals queue has something to show (filed by the operator). */
    private function seedCorrection(): void
    {
        $operator = User::where('role', Role::Operator->value)->first();
        $movement = CargoMovement::where('idempotency_key', 'dy-out-2')->first();

        if (! $operator || ! $movement || CorrectionRequest::exists()) {
            return;
        }

        auth()->setUser($operator);
        try {
            app(RequestCorrection::class)($movement, $operator,
                'Weighbridge ticket shows 340,000 kg, not 350,000. I keyed the wrong figure.');
        } finally {
            auth()->forgetGuards();
        }
    }
}
