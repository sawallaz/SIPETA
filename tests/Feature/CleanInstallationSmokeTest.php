<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Education;
use App\Models\Occupation;
use App\Models\Religion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanInstallationSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Simulate clean initial installation state: Only Admin and Master Reference Data
        $this->admin = User::create([
            'name' => 'Administrator',
            'email' => 'admin@sipeta.test',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
        ]);

        Religion::firstOrCreate(['name' => 'Islam']);
        Education::firstOrCreate(['name' => 'SMA']);
        Occupation::firstOrCreate(['name' => 'Wiraswasta']);
    }

    public function test_login_page_renders(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Masuk');
    }

    public function test_clean_dashboard_renders_without_errors(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get('/admin');
        $response->assertOk();
        $response->assertSee('Dasbor');
        $response->assertSee('Akses Cepat');
        $response->assertSee('Data Penduduk');
        $response->assertSee('Data Kartu Keluarga');
        $response->assertSee('Aktivitas Terbaru');
    }

    public function test_clean_penduduk_list_page_renders_empty_state(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get('/admin/penduduks');
        $response->assertOk();
        $response->assertSee('Penduduk');
    }

    public function test_create_penduduk_page_renders_with_master_data_available(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get('/admin/penduduks/create');
        $response->assertOk();
        $response->assertSee('Tambah Penduduk');
    }

    public function test_clean_kartu_keluarga_list_page_renders_empty_state(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get('/admin/kartu-keluargas');
        $response->assertOk();
        $response->assertSee('Kartu Keluarga');
    }

    public function test_create_kartu_keluarga_page_renders(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get('/admin/kartu-keluargas/create');
        $response->assertOk();
        $response->assertSee('Tambah Kartu Keluarga');
    }

    public function test_backup_page_renders_clean(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get('/admin/backup');
        $response->assertOk();
        $response->assertSee('Backup & Restore');
    }
}
