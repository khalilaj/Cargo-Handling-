<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_to_dashboard_and_login_loads(): void
    {
        $this->get('/')->assertRedirect('/admin');
        $this->get('/admin/login')->assertOk();
    }
}
