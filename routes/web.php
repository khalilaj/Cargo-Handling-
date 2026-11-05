<?php

use App\Http\Controllers\ReportPdfController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Combined PDF of the three reports. Signed-in users only (guests are sent to the admin login).
Route::get('/reports/pdf', ReportPdfController::class)->middleware('auth')->name('reports.pdf');
