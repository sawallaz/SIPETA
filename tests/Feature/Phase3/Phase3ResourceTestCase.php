<?php

namespace Tests\Feature\Phase3;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Shared base for Phase 3 Filament Resource feature tests.
 *
 * Runs against the default SQLite in-memory connection (phpunit.xml) with real
 * migrations, and authenticates a User so panel pages are reachable.
 *
 * `app.env` is pinned to `local` to mirror the real operator runtime: Filament 4's
 * Authenticate middleware only admits a User that does NOT implement FilamentUser
 * when `config('app.env') === 'local'`. phpunit.xml forces APP_ENV=testing, which
 * would otherwise yield 403 before any resource code runs.
 */
abstract class Phase3ResourceTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.env', 'local');

        $this->admin = User::factory()->create();

        $this->actingAs($this->admin);
    }
}
