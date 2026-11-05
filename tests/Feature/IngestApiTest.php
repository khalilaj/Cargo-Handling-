<?php

namespace Tests\Feature;

use App\Domain\Cargo\Enums\Commodity;
use App\Models\Tank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ingest.token' => 'secret']);
        Tank::create(['code' => 'CPOL-01', 'name' => 'T', 'commodity' => Commodity::CPOL, 'capacity_kg' => 1_000_000]);
    }

    private function rec(string $type, int $kg, string $at, string $key): array
    {
        return ['tank' => 'CPOL-01', 'type' => $type, 'quantity_kg' => $kg, 'occurred_at' => $at, 'idempotency_key' => $key];
    }

    public function test_requires_token(): void
    {
        $this->postJson('/api/movements/ingest', ['records' => []])->assertUnauthorized();
    }

    public function test_shuffled_batch_is_sorted_and_applied(): void
    {
        // Delivery listed first although it happened after the discharge.
        $res = $this->withToken('secret')->postJson('/api/movements/ingest', ['records' => [
            $this->rec('truck_delivery', 30_000, '2026-11-05 10:00:00', 'k2'),
            $this->rec('vessel_discharge', 100_000, '2026-11-05 08:00:00', 'k1'),
        ]])->assertOk();

        $this->assertSame(['applied', 'applied'], collect($res->json('results'))->pluck('status')->all());
        $this->assertSame(69_500, Tank::first()->current_volume_kg);
    }

    public function test_overflow_is_reported_per_record_and_others_still_apply(): void
    {
        $res = $this->withToken('secret')->postJson('/api/movements/ingest', ['records' => [
            $this->rec('vessel_discharge', 900_000, '2026-11-05 08:00:00', 'k1'),
            $this->rec('vessel_discharge', 900_000, '2026-11-05 09:00:00', 'k2'),
        ]])->assertOk();

        $this->assertSame(['applied', 'rejected'], collect($res->json('results'))->pluck('status')->all());
        $this->assertSame(895_500, Tank::first()->current_volume_kg);
    }

    public function test_replaying_the_same_batch_changes_nothing(): void
    {
        $batch = ['records' => [$this->rec('vessel_discharge', 100_000, '2026-11-05 08:00:00', 'k1')]];
        $this->withToken('secret')->postJson('/api/movements/ingest', $batch)->assertOk();
        $this->withToken('secret')->postJson('/api/movements/ingest', $batch)->assertOk();

        $this->assertSame(99_500, Tank::first()->current_volume_kg);
    }
}
