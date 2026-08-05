<?php

namespace Tests\Feature\Phase3;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 3.1 — Filament panel foundation smoke tests.
 *
 * Verifies the admin panel boots and the login route is reachable.
 * Does NOT test any Resource/CRUD (out of scope for 3.1).
 */
class AdminPanelTest extends TestCase
{
    public function test_admin_login_page_loads(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_admin_panel_route_is_registered(): void
    {
        $this->assertTrue(
            Route::has('filament.admin.auth.login'),
            'Expected the filament.admin.auth.login route to be registered.',
        );
    }
}
