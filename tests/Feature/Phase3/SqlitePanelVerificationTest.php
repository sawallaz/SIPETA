<?php

namespace Tests\Feature\Phase3;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 3.1 — Filament admin panel verification against the SQLite database.
 */
class SqlitePanelVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Administrator',
            'email' => 'admin@sipeta.test',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
        ]);
    }

    public function test_admin_login_page_renders_against_sqlite(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_admin_authenticates_via_filament_login_against_sqlite(): void
    {
        Livewire::test(Login::class)
            ->set('data', [
                'email' => $this->admin->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect('/admin');

        $this->assertTrue(Auth::check(), 'Admin should be authenticated after Filament login.');
    }

    public function test_dashboard_loads_for_authenticated_admin(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertOk();
    }
}
