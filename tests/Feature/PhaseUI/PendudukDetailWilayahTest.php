<?php

namespace Tests\Feature\PhaseUI;

use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Enums\UserRole;
use App\Models\AreaUnit;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Models\Rt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendudukDetailWilayahTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AreaUnit $areaRw;

    private AreaUnit $areaLingkungan;

    private Rt $rt01;

    private Rt $rt02;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@sipeta.test',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
        ]);

        $this->areaRw = AreaUnit::create([
            'name' => 'RW 01',
            'type' => 'rw',
            'code' => '01',
        ]);

        $this->areaLingkungan = AreaUnit::create([
            'name' => 'Lingkungan Melati',
            'type' => 'lingkungan',
            'code' => 'I',
        ]);

        $this->rt01 = Rt::create([
            'number' => '01',
            'area_unit_id' => $this->areaRw->id,
        ]);

        $this->rt02 = Rt::create([
            'number' => '02',
            'area_unit_id' => $this->areaLingkungan->id,
        ]);
    }

    public function test_penduduk_with_rt_and_rw_separates_fields_correctly(): void
    {
        $this->actingAs($this->admin);

        $kk = KartuKeluarga::create([
            'kk_number' => '7371000000000001',
            'address' => 'Jl. Mawar No. 1',
            'rt_id' => $this->rt01->id,
        ]);

        $penduduk = Penduduk::factory()->create([
            'kk_id' => $kk->id,
            'nik' => '7371000000000001',
            'full_name' => 'Budi Santoso',
            'gender' => Gender::LAKI_LAKI,
            'birth_place' => 'Makassar',
            'birth_date' => '1990-01-01',
            'resident_status' => ResidentStatus::ACTIVE,
            'rt_id' => $this->rt01->id,
        ]);

        $response = $this->getJson("/admin/penduduk/{$penduduk->id}/detail");
        $response->assertOk();
        $response->assertJson([
            'rt' => 'RT 01',
            'rw' => 'RW 01',
            'address' => 'Jl. Mawar No. 1',
        ]);
    }

    public function test_penduduk_with_lingkungan_resolves_area_unit_name(): void
    {
        $this->actingAs($this->admin);

        $kk = KartuKeluarga::create([
            'kk_number' => '7371000000000002',
            'address' => 'Jl. Melati No. 2',
            'rt_id' => $this->rt02->id,
        ]);

        $penduduk = Penduduk::factory()->create([
            'kk_id' => $kk->id,
            'nik' => '7371000000000002',
            'full_name' => 'Siti Rahma',
            'gender' => Gender::PEREMPUAN,
            'birth_place' => 'Makassar',
            'birth_date' => '1995-05-05',
            'resident_status' => ResidentStatus::ACTIVE,
            'rt_id' => $this->rt02->id,
        ]);

        $response = $this->getJson("/admin/penduduk/{$penduduk->id}/detail");
        $response->assertOk();
        $response->assertJson([
            'rt' => 'RT 02',
            'rw' => 'Lingkungan Melati',
            'address' => 'Jl. Melati No. 2',
        ]);
    }

    public function test_penduduk_with_custom_address_renders_correctly(): void
    {
        $this->actingAs($this->admin);

        $kk = KartuKeluarga::create([
            'kk_number' => '7371000000000004',
            'address' => 'Jl. Pattimura No. 44',
            'rt_id' => $this->rt01->id,
        ]);

        $penduduk = Penduduk::factory()->create([
            'kk_id' => $kk->id,
            'nik' => '7371000000000004',
            'full_name' => 'Warga Pattimura',
            'gender' => Gender::LAKI_LAKI,
            'birth_place' => 'Makassar',
            'birth_date' => '1992-02-02',
            'resident_status' => ResidentStatus::ACTIVE,
            'rt_id' => $this->rt01->id,
        ]);

        $response = $this->getJson("/admin/penduduk/{$penduduk->id}/detail");
        $response->assertOk();
        $response->assertJson([
            'rt' => 'RT 01',
            'rw' => 'RW 01',
            'address' => 'Jl. Pattimura No. 44',
        ]);
    }

    public function test_penduduk_detail_modal_view_renders_rt_and_rw_separately(): void
    {
        $this->actingAs($this->admin);

        $kk = KartuKeluarga::create([
            'kk_number' => '7371000000000003',
            'address' => 'JL. DUSUN TANETE GANG 11 BLOCK 2',
            'rt_id' => $this->rt01->id,
        ]);

        $penduduk = Penduduk::factory()->create([
            'kk_id' => $kk->id,
            'nik' => '7371000000000003',
            'full_name' => 'Agus Subroto',
            'gender' => Gender::LAKI_LAKI,
            'birth_place' => 'Makassar',
            'birth_date' => '1988-08-08',
            'resident_status' => ResidentStatus::ACTIVE,
            'rt_id' => $this->rt01->id,
        ]);

        $view = $this->view('filament.components.penduduk-detail-modal', ['record' => $penduduk]);
        $view->assertSee('Informasi Wilayah');
        $view->assertSee('Nomor KK');
        $view->assertSee('RT 01');
        $view->assertSee('RW 01');
        $view->assertSee('JL. DUSUN TANETE GANG 11 BLOCK 2');
        $view->assertDontSee('RW / Wilayah');
    }

    public function test_penduduk_detail_modal_view_renders_lingkungan_correctly(): void
    {
        $this->actingAs($this->admin);

        $kk = KartuKeluarga::create([
            'kk_number' => '7371000000000005',
            'address' => 'JL. POROS TANETE NO. 12',
            'rt_id' => $this->rt02->id,
        ]);

        $penduduk = Penduduk::factory()->create([
            'kk_id' => $kk->id,
            'nik' => '7371000000000005',
            'full_name' => 'Budi Lingkungan',
            'gender' => Gender::LAKI_LAKI,
            'birth_place' => 'Makassar',
            'birth_date' => '1990-01-01',
            'resident_status' => ResidentStatus::ACTIVE,
            'rt_id' => $this->rt02->id,
        ]);

        $view = $this->view('filament.components.penduduk-detail-modal', ['record' => $penduduk]);
        $view->assertSee('Informasi Wilayah');
        $view->assertSee('Nomor KK');
        $view->assertSee('RT 02');
        $view->assertSee('Lingkungan Melati');
        $view->assertSee('JL. POROS TANETE NO. 12');
    }
}
