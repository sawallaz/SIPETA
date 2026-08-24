<?php

namespace Tests\Feature\Phase3;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Filament\Resources\KartuKeluargas\Pages\EditKartuKeluarga;
use App\Filament\Resources\Penduduks\Pages\EditPenduduk;
use App\Models\AreaUnit;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\Occupation;
use App\Models\Penduduk;
use App\Models\Religion;
use App\Models\Rt;
use Livewire\Livewire;

class CrudEditIntegrityAuditTest extends Phase3ResourceTestCase
{
    private Religion $religionIslam;

    private Religion $religionKristen;

    private Education $eduSD;

    private Education $eduS1;

    private Occupation $occWiraswasta;

    private Occupation $occPNS;

    private AreaUnit $area1;

    private AreaUnit $area2;

    private Rt $rt1;

    private Rt $rt2;

    private KartuKeluarga $kk1;

    private KartuKeluarga $kk2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->religionIslam = Religion::factory()->create(['name' => 'Islam']);
        $this->religionKristen = Religion::factory()->create(['name' => 'Kristen']);
        $this->eduSD = Education::factory()->create(['name' => 'Tamat SD/Sederajat']);
        $this->eduS1 = Education::factory()->create(['name' => 'Diploma IV/Strata I']);
        $this->occWiraswasta = Occupation::factory()->create(['name' => 'Wiraswasta']);
        $this->occPNS = Occupation::factory()->create(['name' => 'Pegawai Negeri Sipil']);

        $this->area1 = AreaUnit::factory()->create(['name' => 'RW 01']);
        $this->rt1 = Rt::factory()->create(['area_unit_id' => $this->area1->id, 'number' => '01']);

        $this->area2 = AreaUnit::factory()->create(['name' => 'RW 02']);
        $this->rt2 = Rt::factory()->create(['area_unit_id' => $this->area2->id, 'number' => '02']);

        $this->kk1 = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010001',
            'address' => 'Jl. Asli No. 1',
            'rt_id' => $this->rt1->id,
        ]);

        $this->kk2 = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010002',
            'address' => 'Jl. Asli No. 2',
            'rt_id' => $this->rt2->id,
        ]);
    }

    private function createSamplePenduduk(array $attributes = []): Penduduk
    {
        return Penduduk::factory()->create(array_merge([
            'kk_id' => $this->kk1->id,
            'nik' => '7371010101010011',
            'full_name' => 'Budi Santoso',
            'gender' => Gender::LAKI_LAKI,
            'birth_place' => 'Makassar',
            'birth_date' => '1990-01-01',
            'religion_id' => $this->religionIslam->id,
            'education_id' => $this->eduSD->id,
            'occupation_id' => $this->occWiraswasta->id,
            'marital_status' => MaritalStatus::BELUM_KAWIN,
            'family_relation' => FamilyRelation::ANAK,
            'resident_status' => ResidentStatus::ACTIVE,
            'active_at' => '2026-01-01',
            'notes' => 'Catatan awal',
        ], $attributes));
    }

    public function test_audit_01_edit_nama_updates_current_record_with_zero_history_delta(): void
    {
        $penduduk = $this->createSamplePenduduk();
        $historyCountBefore = $penduduk->statusHistories()->count();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm(['full_name' => 'Budi Santoso Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = $penduduk->fresh();
        $this->assertSame('Budi Santoso Baru', $reloaded->full_name);
        $this->assertSame($historyCountBefore, $reloaded->statusHistories()->count());
    }

    public function test_audit_02_edit_pekerjaan_dan_pendidikan_updates_current_with_zero_history_delta(): void
    {
        $penduduk = $this->createSamplePenduduk();
        $historyCountBefore = $penduduk->statusHistories()->count();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm([
                'occupation_id' => $this->occPNS->id,
                'education_id' => $this->eduS1->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = $penduduk->fresh();
        $this->assertSame($this->occPNS->id, $reloaded->occupation_id);
        $this->assertSame($this->eduS1->id, $reloaded->education_id);
        $this->assertSame($historyCountBefore, $reloaded->statusHistories()->count());
    }

    public function test_audit_03_edit_catatan_updates_current_with_zero_history_delta(): void
    {
        $penduduk = $this->createSamplePenduduk();
        $historyCountBefore = $penduduk->statusHistories()->count();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm(['notes' => 'Catatan revisi operasional'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = $penduduk->fresh();
        $this->assertSame('Catatan revisi operasional', $reloaded->notes);
        $this->assertSame($historyCountBefore, $reloaded->statusHistories()->count());
    }

    public function test_audit_04_edit_status_active_to_pindah_records_exactly_one_history_delta(): void
    {
        $penduduk = $this->createSamplePenduduk();
        $historyCountBefore = $penduduk->statusHistories()->count();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm([
                'resident_status' => ResidentStatus::PINDAH->value,
                'moved_at' => '2026-08-15',
                'moved_destination' => 'Kabupaten Gowa',
                'moved_note' => 'Pindah domisili kerja',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = $penduduk->fresh();
        $this->assertSame(ResidentStatus::PINDAH, $reloaded->resident_status);
        $this->assertSame('2026-08-15', $reloaded->moved_at->format('Y-m-d'));
        $this->assertSame($historyCountBefore + 1, $reloaded->statusHistories()->count());

        $latestHistory = $reloaded->statusHistories()->latest('id')->first();
        $this->assertSame(ResidentStatus::PINDAH, $latestHistory->status);
        $this->assertSame('2026-08-15', $latestHistory->recorded_at->format('Y-m-d'));
    }

    public function test_audit_05_non_status_edit_does_not_create_status_history(): void
    {
        $penduduk = $this->createSamplePenduduk();
        $historyCountBefore = $penduduk->statusHistories()->count();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm([
                'full_name' => 'Budi Santoso Edit',
                'religion_id' => $this->religionKristen->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = $penduduk->fresh();
        $this->assertSame($historyCountBefore, $reloaded->statusHistories()->count());
    }

    public function test_audit_06_validation_failure_leaves_database_and_history_unchanged(): void
    {
        $penduduk = $this->createSamplePenduduk();
        $historyCountBefore = $penduduk->statusHistories()->count();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm([
                'full_name' => '', // Required field empty
                'nik' => '123',   // Invalid NIK length
            ])
            ->call('save')
            ->assertHasFormErrors(['full_name', 'nik']);

        $reloaded = $penduduk->fresh();
        $this->assertSame('Budi Santoso', $reloaded->full_name);
        $this->assertSame('7371010101010011', $reloaded->nik);
        $this->assertSame($historyCountBefore, $reloaded->statusHistories()->count());
    }

    public function test_audit_07_duplicate_nik_against_another_resident_is_blocked(): void
    {
        $pendudukA = $this->createSamplePenduduk(['nik' => '7371010101010011']);
        $pendudukB = $this->createSamplePenduduk(['nik' => '7371010101010022']);

        Livewire::test(EditPenduduk::class, ['record' => $pendudukB->getKey()])
            ->fillForm(['nik' => $pendudukA->nik])
            ->call('save')
            ->assertHasFormErrors(['nik']);

        $this->assertSame('7371010101010022', $pendudukB->fresh()->nik);
    }

    public function test_audit_08_multi_field_edit_updates_all_fields_with_zero_history_delta(): void
    {
        $penduduk = $this->createSamplePenduduk();
        $historyCountBefore = $penduduk->statusHistories()->count();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm([
                'full_name' => 'Budi Santoso S.Kom',
                'birth_place' => 'Gowa',
                'birth_date' => '1990-05-20',
                'religion_id' => $this->religionKristen->id,
                'education_id' => $this->eduS1->id,
                'occupation_id' => $this->occPNS->id,
                'marital_status' => MaritalStatus::KAWIN->value,
                'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
                'notes' => 'Catatan lengkap multi-field',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = $penduduk->fresh();
        $this->assertSame('Budi Santoso S.Kom', $reloaded->full_name);
        $this->assertSame('Gowa', $reloaded->birth_place);
        $this->assertSame('1990-05-20', $reloaded->birth_date->format('Y-m-d'));
        $this->assertSame($this->religionKristen->id, $reloaded->religion_id);
        $this->assertSame($this->eduS1->id, $reloaded->education_id);
        $this->assertSame($this->occPNS->id, $reloaded->occupation_id);
        $this->assertSame(MaritalStatus::KAWIN, $reloaded->marital_status);
        $this->assertSame(FamilyRelation::KEPALA_KELUARGA, $reloaded->family_relation);
        $this->assertSame('Catatan lengkap multi-field', $reloaded->notes);

        $this->assertSame($historyCountBefore, $reloaded->statusHistories()->count());
    }

    public function test_audit_09_status_plus_regular_fields_edit_records_exactly_one_history_delta(): void
    {
        $penduduk = $this->createSamplePenduduk();
        $historyCountBefore = $penduduk->statusHistories()->count();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm([
                'full_name' => 'Budi Santoso Pindah',
                'notes' => 'Catatan kepindahan',
                'resident_status' => ResidentStatus::PINDAH->value,
                'moved_at' => '2026-08-20',
                'moved_destination' => 'Jakarta',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = $penduduk->fresh();
        $this->assertSame('Budi Santoso Pindah', $reloaded->full_name);
        $this->assertSame('Catatan kepindahan', $reloaded->notes);
        $this->assertSame(ResidentStatus::PINDAH, $reloaded->resident_status);
        $this->assertSame($historyCountBefore + 1, $reloaded->statusHistories()->count());
    }

    public function test_audit_10_kartu_keluarga_edit_integrity(): void
    {
        Livewire::test(EditKartuKeluarga::class, ['record' => $this->kk1->getKey()])
            ->fillForm([
                'address' => 'Jl. Poros Baru No. 88',
                'postal_code' => '90711',
                'notes' => 'Catatan KK revisi',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloadedKk = $this->kk1->fresh();
        $this->assertSame('Jl. Poros Baru No. 88', $reloadedKk->address);
        $this->assertSame('90711', $reloadedKk->postal_code);
        $this->assertSame('Catatan KK revisi', $reloadedKk->notes);
    }

    public function test_audit_11_pindah_to_pindah_has_zero_history_delta(): void
    {
        $penduduk = $this->createSamplePenduduk([
            'resident_status' => ResidentStatus::PINDAH,
            'moved_at' => '2026-06-01',
            'moved_destination' => 'Makassar',
        ]);
        $historyCountBefore = $penduduk->statusHistories()->count();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm([
                'full_name' => 'Budi Santoso Pindahan',
                'notes' => 'Catatan perbaikan nama',
                'resident_status' => ResidentStatus::PINDAH->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = $penduduk->fresh();
        $this->assertSame('Budi Santoso Pindahan', $reloaded->full_name);
        $this->assertSame(ResidentStatus::PINDAH, $reloaded->resident_status);
        $this->assertSame($historyCountBefore, $reloaded->statusHistories()->count());
    }

    public function test_audit_12_pindah_to_meninggal_records_one_history_delta(): void
    {
        $penduduk = $this->createSamplePenduduk([
            'resident_status' => ResidentStatus::PINDAH,
            'moved_at' => '2026-06-01',
        ]);
        $historyCountBefore = $penduduk->statusHistories()->count();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm([
                'resident_status' => ResidentStatus::MENINGGAL->value,
                'deceased_at' => '2026-08-22',
                'deceased_note' => 'Meninggal dunia di rumah sakit',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = $penduduk->fresh();
        $this->assertSame(ResidentStatus::MENINGGAL, $reloaded->resident_status);
        $this->assertSame('2026-08-22', $reloaded->deceased_at->format('Y-m-d'));
        $this->assertSame($historyCountBefore + 1, $reloaded->statusHistories()->count());

        $latestHistory = $reloaded->statusHistories()->latest('id')->first();
        $this->assertSame(ResidentStatus::MENINGGAL, $latestHistory->status);
        $this->assertSame('2026-08-22', $latestHistory->recorded_at->format('Y-m-d'));
    }

    public function test_audit_13_duplicate_kk_number_against_another_kk_is_blocked(): void
    {
        Livewire::test(EditKartuKeluarga::class, ['record' => $this->kk2->getKey()])
            ->fillForm(['kk_number' => $this->kk1->kk_number])
            ->call('save')
            ->assertHasFormErrors(['kk_number']);

        $this->assertSame('7371010101010002', $this->kk2->fresh()->kk_number);
    }
}
