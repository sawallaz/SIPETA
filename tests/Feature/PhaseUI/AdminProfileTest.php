<?php

namespace Tests\Feature\PhaseUI;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Feature\Phase3\Phase3ResourceTestCase;

/**
 * Phase UI-6 — Administrator account self-service.
 *
 * The operator manages their own name / email / password through Filament's
 * BUILT-IN profile page (`->profile(isSimple: false)`) and recovers a lost
 * password through the built-in reset flow (`->passwordReset()`), so no custom
 * page and no third-party plugin is introduced. These tests pin both panel
 * features on, prove the routes exist, and prove the profile form actually
 * persists a change with the password stored hashed (never plain text).
 */
class AdminProfileTest extends Phase3ResourceTestCase
{
    public function test_panel_enables_profile_and_password_reset(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasProfile(), 'Panel must expose the built-in profile page.');
        $this->assertFalse($panel->isProfilePageSimple(), 'Profile must use the full panel layout (isSimple: false).');
        $this->assertTrue($panel->hasPasswordReset(), 'Panel must expose the built-in password reset flow.');
    }

    public function test_profile_route_is_reachable(): void
    {
        $this->get('/admin/profile')->assertSuccessful();
    }

    public function test_password_reset_request_route_is_reachable(): void
    {
        Config::set('app.env', 'local');

        auth()->logout();

        $this->get('/admin/password-reset/request')->assertSuccessful();
    }

    public function test_operator_can_update_name_and_email(): void
    {
        Livewire::test(Filament::getPanel('admin')->getProfilePage())
            ->fillForm([
                'name' => 'Administrator Tanete',
                'email' => 'admin.tanete@example.test',
                'currentPassword' => 'password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->admin->refresh();

        $this->assertSame('Administrator Tanete', $this->admin->name);
        $this->assertSame('admin.tanete@example.test', $this->admin->email);
    }

    public function test_operator_can_change_password_and_it_is_stored_hashed(): void
    {
        Livewire::test(Filament::getPanel('admin')->getProfilePage())
            ->fillForm([
                'password' => 'RahasiaBaru#2026',
                'passwordConfirmation' => 'RahasiaBaru#2026',
                'currentPassword' => 'password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->admin->refresh();

        $this->assertNotSame('RahasiaBaru#2026', $this->admin->password);
        $this->assertTrue(Hash::check('RahasiaBaru#2026', $this->admin->password));
    }

    public function test_changing_password_requires_the_current_password(): void
    {
        Livewire::test(Filament::getPanel('admin')->getProfilePage())
            ->fillForm([
                'password' => 'RahasiaBaru#2026',
                'passwordConfirmation' => 'RahasiaBaru#2026',
                'currentPassword' => 'salah-password',
            ])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->admin->refresh();

        $this->assertFalse(Hash::check('RahasiaBaru#2026', $this->admin->password));
    }

    public function test_password_confirmation_must_match(): void
    {
        Livewire::test(Filament::getPanel('admin')->getProfilePage())
            ->fillForm([
                'password' => 'RahasiaBaru#2026',
                'passwordConfirmation' => 'Salah#2026',
                'currentPassword' => 'password',
            ])
            ->call('save')
            ->assertHasFormErrors(['password']);
    }

    public function test_email_must_stay_unique(): void
    {
        User::factory()->create(['email' => 'sudah.dipakai@example.test']);

        Livewire::test(Filament::getPanel('admin')->getProfilePage())
            ->fillForm([
                'email' => 'sudah.dipakai@example.test',
                'currentPassword' => 'password',
            ])
            ->call('save')
            ->assertHasFormErrors(['email']);
    }
}
