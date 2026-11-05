<?php

namespace Database\Seeders;

use App\Domain\Cargo\Actions\RecordTruckDelivery;
use App\Domain\Cargo\Actions\RecordVesselDischarge;
use App\Domain\Cargo\Enums\Commodity;
use App\Models\Tank;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@cargo.test')],
            ['name' => 'Terminal Operator', 'password' => env('ADMIN_PASSWORD', 'password')]
        );

        $tanks = [
            ['CPO-01', 'Crude Palm Oil 1', Commodity::CPO, 5_000_000],
            ['CPO-02', 'Crude Palm Oil 2', Commodity::CPO, 5_000_000],
            ['CPOL-01', 'Palm Olein 1', Commodity::CPOL, 3_000_000],
            ['CPOL-02', 'Palm Olein 2', Commodity::CPOL, 3_000_000],
            ['PFAD-01', 'PFAD 1', Commodity::PFAD, 2_000_000],
        ];
        foreach ($tanks as [$code, $name, $c, $cap]) {
            Tank::updateOrCreate(['code' => $code], [
                'name' => $name, 'commodity' => $c, 'capacity_kg' => $cap,
                'requires_heating' => $c->requiresHeating(),
            ]);
        }

        if (\App\Models\CargoMovement::exists()) {
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
}
