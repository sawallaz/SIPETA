<?php

namespace Tests\Feature\Phase7;

use App\Enums\BloodType;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Filament\Resources\Penduduks\PendudukResource;
use App\Models\AreaUnit;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\Occupation;
use App\Models\Penduduk;
use App\Models\Religion;
use App\Models\Rt;
use App\Models\User;
use Filament\GlobalSearch\GlobalSearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private Rt $rt;

    private AreaUnit $areaUnit;

    private KartuKeluarga $kk;

    private Penduduk $kepala;

    private Penduduk $istri;

    private Penduduk $anak;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN->value,
        ]);
        $this->actingAs($this->admin);

        $this->areaUnit = AreaUnit::create([
            'name' => 'Lingkungan Melati',
            'display_label' => 'Lingkungan Melati',
        ]);

        $this->rt = Rt::create([
            'number' => '01',
            'area_unit_id' => $this->areaUnit->id,
        ]);

        $this->kk = KartuKeluarga::create([
            'kk_number' => '7371000000000011',
            'address' => 'Jl. Melati No. 5',
            'rt_id' => $this->rt->id,
            'postal_code' => '73711',
        ]);

        $religion = Religion::firstOrCreate(['name' => 'Islam']);
        $education = Education::firstOrCreate(['name' => 'SMA']);
        $occupation = Occupation::firstOrCreate(['name' => 'Wiraswasta']);
        $bloodType = BloodType::TIDAK_DIKETAHUI;

        $this->kepala = Penduduk::create([
            'kk_id' => $this->kk->id,
            'nik' => '7371000001000001',
            'full_name' => 'Agus Santoso',
            'gender' => Gender::LAKI_LAKI->value,
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
            'birth_place' => 'Malang',
            'birth_date' => '1980-01-01',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::KAWIN->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'rt_id' => $this->rt->id,
            'blood_type' => $bloodType->value,
        ]);

        $this->istri = Penduduk::create([
            'kk_id' => $this->kk->id,
            'nik' => '7371000001000002',
            'full_name' => 'Siti Aminah',
            'gender' => Gender::PEREMPUAN->value,
            'family_relation' => FamilyRelation::ISTRI->value,
            'birth_place' => 'Malang',
            'birth_date' => '1985-01-01',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::KAWIN->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'rt_id' => $this->rt->id,
            'blood_type' => $bloodType->value,
        ]);

        $this->anak = Penduduk::create([
            'kk_id' => $this->kk->id,
            'nik' => '7371000001000003',
            'full_name' => 'Budi Santoso',
            'gender' => Gender::LAKI_LAKI->value,
            'family_relation' => FamilyRelation::ANAK->value,
            'birth_place' => 'Malang',
            'birth_date' => '2000-01-01',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::BELUM_KAWIN->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'rt_id' => $this->rt->id,
            'blood_type' => $bloodType->value,
        ]);
    }

    private function recordKeyFromUrl(GlobalSearchResult $result): int
    {
        $parsed = parse_url($result->url);
        parse_str($parsed['query'] ?? '', $queryParams);

        return (int) ($queryParams['tableActionRecord'] ?? 0);
    }

    // =========================================================
    // KARTU KELUARGA — SEARCHABLE & TITLE & DETAILS
    // =========================================================

    public function test_kk_searchable_by_kk_number(): void
    {
        $result = KartuKeluargaResource::getGloballySearchableAttributes();

        $this->assertEquals(['kk_number'], $result);
    }

    public function test_kk_search_result_title_uses_kk_number(): void
    {
        $title = KartuKeluargaResource::getGlobalSearchResultTitle($this->kk);

        $this->assertSame('7371000000000011', $title);
    }

    public function test_kk_search_result_details_is_empty_for_clean_dropdown(): void
    {
        $details = KartuKeluargaResource::getGlobalSearchResultDetails($this->kk);

        $this->assertIsArray($details);
        $this->assertEmpty($details);
    }

    public function test_kk_ditemukan_melalui_kk_number(): void
    {
        $results = KartuKeluargaResource::getGlobalSearchResults('7371000000000011');

        $this->assertTrue($results->contains(function (GlobalSearchResult $result) {
            return $this->recordKeyFromUrl($result) === $this->kk->getKey();
        }));
    }

    public function test_kk_search_result_url_mengarahkan_ke_view(): void
    {
        $url = KartuKeluargaResource::getGlobalSearchResultUrl($this->kk);

        $this->assertNotEmpty($url);
        $this->assertStringContainsString('tableAction=lihat', $url);
        $this->assertStringContainsString('tableActionRecord='.$this->kk->getKey(), $url);
    }

    // =========================================================
    // PENDUDUK — SEARCHABLE & TITLE & DETAILS
    // =========================================================

    public function test_penduduk_searchable_by_full_name_and_nik(): void
    {
        $result = PendudukResource::getGloballySearchableAttributes();

        $this->assertEquals(['full_name', 'nik'], $result);
    }

    public function test_penduduk_search_result_title_uses_full_name(): void
    {
        $title = PendudukResource::getGlobalSearchResultTitle($this->kepala);

        $this->assertSame('Agus Santoso', $title);
    }

    public function test_penduduk_search_result_details_is_empty_for_clean_dropdown(): void
    {
        $details = PendudukResource::getGlobalSearchResultDetails($this->kepala);

        $this->assertIsArray($details);
        $this->assertEmpty($details);
    }

    public function test_penduduk_ditemukan_melalui_nama(): void
    {
        $results = PendudukResource::getGlobalSearchResults('Agus');

        $this->assertTrue($results->contains(function (GlobalSearchResult $result) {
            return $this->recordKeyFromUrl($result) === $this->kepala->getKey();
        }));
    }

    public function test_penduduk_ditemukan_melalui_nik(): void
    {
        $results = PendudukResource::getGlobalSearchResults('7371000001000001');

        $this->assertTrue($results->contains(function (GlobalSearchResult $result) {
            return $this->recordKeyFromUrl($result) === $this->kepala->getKey();
        }));
    }

    public function test_penduduk_search_result_url_mengarahkan_ke_view(): void
    {
        $url = PendudukResource::getGlobalSearchResultUrl($this->kepala);

        $this->assertNotEmpty($url);
        $this->assertStringContainsString('tableAction=view', $url);
        $this->assertStringContainsString('tableActionRecord='.$this->kepala->getKey(), $url);
    }
}
