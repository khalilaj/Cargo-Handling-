<?php

use App\Http\Controllers\Api\MovementIngestController;
use Illuminate\Support\Facades\Route;

Route::post('/movements/ingest', MovementIngestController::class);
