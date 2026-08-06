<?php

namespace Tests\Feature\Phase3;

use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 3.1 — Filament admin panel verification against the REAL MySQL database.
 *
 * ENV-GATED: only runs when RUN_MYSQL_TESTS=1. The default `php artisan test`
 * run uses SQLite (per phpunit.xml) and SKIPS this test, so it never touches
 * the real production database during normal CI/local runs.
 *
 * SAFETY: This test does NOT use RefreshDatabase / DatabaseMigrations /
 * migrate:fresh. It only reads the `users` table and writes a standard session
 * row during login. It must never wipe or reset the MySQL schema or data.
 */
class MysqlPanelVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_MYSQL_TESTS')) {
            $this->markTestSkipped('RUN_MYSQL_TESTS not set; skipping real-MySQL verification.');
        }

        // Point this test at the real MySQL database. phpunit.xml forces
        // DB_CONNECTION=sqlite and DB_DATABASE=:memory: for the default suite,
        // so we must explicitly pin the mysql connection's database to `sipeta`
        // (other mysql connection keys — host/port/user/password — are read from
        // .env and are not overridden by phpunit.xml).
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.database', 'sipeta');
    }

    public function test_admin_login_page_renders_against_mysql(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_admin_authenticates_via_filament_login_against_mysql(): void
    {
        $user = User::where('email', 'admin@sipeta.test')->firstOrFail();

        Livewire::test(Login::class)
            ->set('data', [
                'email' => $user->email,
                'password' => env('ADMIN_PASSWORD', 'password'),
            ])
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect('/admin');

        $this->assertTrue(Auth::check(), 'Admin should be authenticated after Filament login.');
    }

    public function test_dashboard_loads_for_authenticated_admin(): void
    {
        // Phase 3.5 implemented FilamentUser::canAccessPanel() on User, so panel
        // access no longer depends on config('app.env') === 'local'. The explicit
        // env pin previously required here has been removed.
        $user = User::where('email', 'admin@sipeta.test')->firstOrFail();

        $this->actingAs($user)->get('/admin')->assertOk();
    }
}
