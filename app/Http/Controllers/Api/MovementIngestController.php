<?php

namespace App\Http\Controllers\Api;

use App\Domain\Cargo\Actions\IngestMovements;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Cargo\Enums\MovementType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MovementIngestController
{
    public function __invoke(Request $request, IngestMovements $ingest): JsonResponse
    {
        $expected = config('services.ingest.token');
        abort_unless($expected && hash_equals($expected, (string) $request->bearerToken()), 401);

        $data = $request->validate([
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*.tank' => ['required', 'string'],
            'records.*.type' => ['required', Rule::enum(MovementType::class)],
            'records.*.quantity_kg' => ['required', 'integer', 'min:1'],
            'records.*.occurred_at' => ['required', 'date'],
            'records.*.idempotency_key' => ['required', 'string', 'max:100'],
            'records.*.reference' => ['nullable', 'string', 'max:120'],
            'records.*.commodity' => ['nullable', Rule::enum(Commodity::class)],
        ]);

        return response()->json(['results' => $ingest($data['records'])]);
    }
}
