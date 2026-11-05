<?php

namespace Tests\Feature;

use App\Domain\Cargo\Enums\Commodity;
use App\Models\CargoMovement;
use App\Models\Tank;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real parallel OS processes against a real database server. Row locks are
 * meaningless on SQLite, so this runs only when DB_CONNECTION is pgsql/mysql:
 *   DB_CONNECTION=pgsql DB_DATABASE=cargo_test ... php artisan test --filter Concurrency
 */
class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('Needs a server database (pgsql/mysql) to exercise row locks.');
        }
    }

    /** @param Process[] $procs */
    private function runAll(array $procs): void
    {
        foreach ($procs as $p) { $p->start(); }
        foreach ($procs as $p) { $p->wait(); }
    }

    private function proc(string ...$args): Process
    {
        return new Process([PHP_BINARY, 'artisan', 'cargo:record', ...$args], base_path(), null, null, 60);
    }

    public function test_parallel_discharges_never_overfill_the_tank(): void
    {
        // Capacity 1,000,000 kg; each discharge nets 99,500 -> exactly 10 fit.
        $tank = Tank::create(['code' => 'RACE-1', 'name' => 'Race', 'commodity' => Commodity::CPOL, 'capacity_kg' => 1_000_000]);

        $procs = [];
        for ($i = 1; $i <= 20; $i++) {
            $procs[] = $this->proc('RACE-1', 'vessel_discharge', '100000', "race-$i", '--at=2026-11-05 08:' . sprintf('%02d', $i) . ':00');
        }
        $this->runAll($procs);

        $applied = collect($procs)->filter(fn ($p) => str_contains($p->getOutput(), 'applied'))->count();

        $this->assertSame(10, $applied, 'exactly 10 of 20 should fit');
        $this->assertSame(10, CargoMovement::count());
        $this->assertSame(995_000, $tank->fresh()->current_volume_kg);
    }

    public function test_parallel_submissions_with_same_key_apply_once(): void
    {
        Tank::create(['code' => 'RACE-2', 'name' => 'Race', 'commodity' => Commodity::CPOL, 'capacity_kg' => 1_000_000]);

        $procs = [];
        for ($i = 0; $i < 12; $i++) {
            $procs[] = $this->proc('RACE-2', 'vessel_discharge', '100000', 'same-key', '--at=2026-11-05 08:00:00');
        }
        $this->runAll($procs);

        $this->assertSame(1, CargoMovement::count());
        $this->assertSame(99_500, Tank::where('code', 'RACE-2')->first()->current_volume_kg);
    }
}
