<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filament only lets users into a panel outside the "local" environment when
 * the User model implements FilamentUser. This guards the production login.
 */
class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_logged_in_operator_can_open_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertOk();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
