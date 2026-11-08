<?php

namespace App\Providers;

use App\Http\Controllers\NotFoundController;
use App\Livewire\AlertsBell;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::component('alerts-bell', AlertsBell::class);   // the bell in the top bar

        // The terminal runs on Kenya time. Times stay stored in UTC (so every existing record stays correct);
        // the panel shows and accepts Kenya time in tables, detail pages and date pickers.
        FilamentTimezone::set('Africa/Nairobi');

        // Filament ships download routes for its import/export feature. We do not use that feature, so the
        // tables behind them do not exist and any request would crash with a database error (HTTP 500).
        // Close them: a plain 404 instead. Done once the app has booted, so the cached route file keeps it.
        $this->app->booted(function () {
            foreach (Route::getRoutes() as $route) {
                if (str_starts_with($route->uri(), 'filament/exports/') || str_starts_with($route->uri(), 'filament/imports/')) {
                    $route->uses(NotFoundController::class);
                }
            }
        });
    }
}
