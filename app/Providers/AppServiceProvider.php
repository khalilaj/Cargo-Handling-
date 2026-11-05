<?php

namespace App\Providers;

use App\Livewire\AlertsBell;
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
    }
}
