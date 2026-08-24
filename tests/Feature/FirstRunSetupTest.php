<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class FirstRunSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_uninitialized_system_redirects_to_setup_page(): void
    {
        $this->assertDatabaseEmpty('users');

        $response = $this->get('/admin');
        $response->assertRedirect(route('setup'));
    }

    public function test_setup_page_renders_with_required_fields(): void
    {
        $this->assertDatabaseEmpty('users');

        $response = $this->get('/setup');
        $response->assertOk();
        $response->assertSee('Setup SIPETA');
        $response->assertSee('Nama Lengkap');
        $response->assertSee('Email Super Admin');
        $response->assertSee('Password');
        $response->assertSee('Konfirmasi Password');
        $response->assertSee('Mulai SIPETA');
    }

    public function test_setup_validation_requires_all_fields(): void
    {
        $response = $this->post('/setup', []);

        $response->assertSessionHasErrors(['name', 'email', 'password']);
    }

    public function test_setup_validation_rejects_invalid_email_and_short_password(): void
    {
        $response = $this->post('/setup', [
            'name' => 'Admin Test',
            'email' => 'bukan-email',
            'password' => '123',
            'password_confirmation' => '123',
        ]);

        $response->assertSessionHasErrors(['email', 'password']);
    }

    public function test_setup_validation_requires_password_confirmation_to_match(): void
    {
        $response = $this->post('/setup', [
            'name' => 'Admin Test',
            'email' => 'admin@kelurahan.go.id',
            'password' => 'password123',
            'password_confirmation' => 'different123',
        ]);

        $response->assertSessionHasErrors(['password']);
    }

    public function test_setup_creates_super_admin_and_logs_in(): void
    {
        $this->assertDatabaseEmpty('users');

        $response = $this->post('/setup', [
            'name' => 'Budi Santoso',
            'email' => 'admin@kelurahan.go.id',
            'password' => 'AdminKuat2026!',
            'password_confirmation' => 'AdminKuat2026!',
        ]);

        $response->assertRedirect('/admin');

        $this->assertDatabaseHas('users', [
            'name' => 'Budi Santoso',
            'email' => 'admin@kelurahan.go.id',
            'role' => UserRole::SUPER_ADMIN->value,
        ]);

        $user = User::where('email', 'admin@kelurahan.go.id')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue(Hash::check('AdminKuat2026!', $user->password));
        $this->assertNotEquals('AdminKuat2026!', $user->password, 'Password must be hashed in database.');

        $this->assertTrue(Auth::check());
        $this->assertEquals($user->id, Auth::id());
    }

    public function test_setup_page_is_inaccessible_once_super_admin_exists(): void
    {
        User::factory()->create([
            'role' => UserRole::SUPER_ADMIN,
        ]);

        $response = $this->get('/setup');
        $response->assertRedirect('/admin');

        $storeResponse = $this->post('/setup', [
            'name' => 'Another Admin',
            'email' => 'another@kelurahan.go.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
        $storeResponse->assertRedirect('/admin');

        $this->assertDatabaseMissing('users', [
            'email' => 'another@kelurahan.go.id',
        ]);
    }

    public function test_login_works_with_credentials_created_during_setup(): void
    {
        // 1. First run setup
        $this->post('/setup', [
            'name' => 'Admin Kelurahan',
            'email' => 'admin@tanete.go.id',
            'password' => 'SipetaPass2026!',
            'password_confirmation' => 'SipetaPass2026!',
        ]);

        Auth::logout();
        $this->assertFalse(Auth::check());

        // 2. Filament login attempt
        Livewire::test(Login::class)
            ->set('data', [
                'email' => 'admin@tanete.go.id',
                'password' => 'SipetaPass2026!',
            ])
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect('/admin');

        $this->assertTrue(Auth::check());
        $this->assertEquals('admin@tanete.go.id', Auth::user()->email);
    }
}
