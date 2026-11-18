<?php

use App\Http\Controllers\Api\MovementIngestController;
use Illuminate\Support\Facades\Route;

// At most 60 batches a minute per caller (each batch holds up to 500 records).
Route::post('/movements/ingest', MovementIngestController::class)->middleware('throttle:60,1');
