<?php

namespace Tests\Feature\Phase6;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 6.5 — operator "Pengaturan" (Settings) page. Verifies the singleton
 * settings row is created on first access and never deleted (FR-SET-02), the
 * identity and logo fields are editable and persist (FR-SET-01), the logo is
 * stored on the `local` disk under the `logos/` prefix with only the relative
 * path persisted.
 */
class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_page_loads_with_identity_and_logo_fields(): void
    {
        $this->get(Settings::getUrl())
            ->assertOk()
            ->assertSee('Pengaturan')
            ->assertSee('Identitas Kelurahan')
            ->assertSee('Logo Kelurahan')
            ->assertDontSee('Lokasi Backup')
            ->assertSee('Simpan')
            ->assertDontSee('SIMPAN');
    }

    public function test_singleton_row_is_created_on_first_access(): void
    {
        $setting = app(SettingsService::class)->get();

        $this->assertSame(1, $setting->id);
        $this->assertDatabaseCount('settings', 1);
        $this->assertSame('Kelurahan Tanete', $setting->kelurahan_name);
    }

    public function test_identity_and_logo_fields_persist_on_save(): void
    {
        Livewire::test(Settings::class)
            ->fillForm([
                'kelurahan_name' => 'Kelurahan Sumpang Binangae',
                'kecamatan_name' => 'Barru',
                'kabupaten_name' => 'Kabupaten Barru',
                'province_name' => 'Sulawesi Selatan',
            ])
            ->call('save')
            ->assertNotified('Pengaturan tersimpan');

        $setting = Setting::query()->find(1);

        $this->assertSame('Kelurahan Sumpang Binangae', $setting->kelurahan_name);
        $this->assertSame('Barru', $setting->kecamatan_name);
        $this->assertSame('Kabupaten Barru', $setting->kabupaten_name);
        $this->assertSame('Sulawesi Selatan', $setting->province_name);
        $this->assertDatabaseCount('settings', 1);
    }

    public function test_logo_is_stored_relative_to_local_disk_logos_directory(): void
    {
        Livewire::test(Settings::class)
            ->fillForm([
                'kelurahan_name' => 'Kelurahan Tanete',
                'kecamatan_name' => 'Tanete',
                'kabupaten_name' => 'Barru',
                'province_name' => 'Sulawesi Selatan',
                'logo_path' => UploadedFile::fake()->image('kota.png'),
            ])
            ->call('save')
            ->assertNotified('Pengaturan tersimpan');

        $setting = Setting::query()->find(1);

        $this->assertNotNull($setting->logo_path);
        $this->assertStringStartsWith('logos/', $setting->logo_path);
        $this->assertTrue(Storage::disk('local')->exists($setting->logo_path));
    }

    public function test_identity_fields_are_required(): void
    {
        Livewire::test(Settings::class)
            ->fillForm([
                'kelurahan_name' => '',
                'kecamatan_name' => '',
                'kabupaten_name' => '',
                'province_name' => '',
            ])
            ->call('save')
            ->assertHasFormErrors([
                'kelurahan_name',
                'kecamatan_name',
                'kabupaten_name',
                'province_name',
            ]);
    }

    public function test_settings_page_does_not_contain_riwayat_kk_shortcut(): void
    {
        $this->get(Settings::getUrl())
            ->assertOk()
            ->assertDontSee('Riwayat Kartu Keluarga');
    }
}
