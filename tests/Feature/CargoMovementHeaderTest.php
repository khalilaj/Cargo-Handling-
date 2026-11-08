<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Filament\Resources\CargoMovementResource\Pages\ListCargoMovements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\RoleScenario;
use Tests\TestCase;

/** The Movements page is the ledger: no booking buttons, cargo is booked through the wizards. */
class CargoMovementHeaderTest extends TestCase
{
    use RefreshDatabase;
    use RoleScenario;

    public function test_no_role_gets_booking_buttons_on_the_movements_page(): void
    {
        $this->seedWithEdgeStates();

        foreach (Role::cases() as $role) {
            $this->loginAs($role);

            Livewire::test(ListCargoMovements::class)->assertSuccessful()
                ->assertDontSee('Record vessel discharge')->assertDontSee('Record truck delivery')
                ->assertDontSee('Schedule a vessel discharge')->assertDontSee('Take a delivery order');
        }
    }
}
