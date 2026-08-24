<?php

namespace Tests\Feature\Phase3;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaDeleteGuard;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\Occupation;
use App\Models\Penduduk;
use App\Models\PendudukStatusHistory;
use App\Models\Religion;
use App\Services\OcrParsingService;
use App\Services\ParsedOcrResult;
use App\Services\PendudukKkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinalIntegrityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_ocr_members_resolves_slta_sederajat_to_canonical_education(): void
    {
        $education = Education::firstOrCreate(['name' => 'SMA']);
        $religion = Religion::firstOrCreate(['name' => 'Islam']);
        $occupation = Occupation::firstOrCreate(['name' => 'Buruh']);

        $kk = KartuKeluarga::factory()->create();

        $service = app(PendudukKkService::class);

        $saved = $service->saveOcrMembers($kk, [
            [
                'nik' => '7372010101900001',
                'full_name' => 'Warga Uji OCR',
                'gender' => 'LAKI_LAKI',
                'birth_place' => 'Parepare',
                'birth_date' => '1990-01-01',
                'religion' => 'ISLAM',
                'education' => 'SLTA/SEDERAJAT',
                'occupation' => 'BURUH',
                'marital_status' => 'KAWIN',
                'family_relation' => 'KEPALA_KELUARGA',
            ],
        ]);

        $this->assertCount(1, $saved);
        $this->assertSame((int) $education->id, (int) $saved[0]->education_id);
        $this->assertSame('7372010101900001', $saved[0]->nik);
        $this->assertSame($kk->id, $saved[0]->kk_id);
    }

    public function test_create_penduduk_rejects_duplicate_nik(): void
    {
        $education = Education::firstOrCreate(['name' => 'SMA']);
        $religion = Religion::firstOrCreate(['name' => 'Islam']);
        $occupation = Occupation::firstOrCreate(['name' => 'Buruh']);

        $kk1 = KartuKeluarga::factory()->create();
        $kk2 = KartuKeluarga::factory()->create();

        $service = app(PendudukKkService::class);

        // First creation succeeds
        $service->save([
            'nik' => '7372010101900002',
            'full_name' => 'Penduduk Pertama',
            'gender' => Gender::LAKI_LAKI->value,
            'birth_place' => 'Parepare',
            'birth_date' => '1990-01-01',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::KAWIN->value,
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'kk_id' => $kk1->id,
        ]);

        // Attempting to CREATE another resident with same NIK must throw ValidationException
        $this->expectException(ValidationException::class);

        $service->save([
            'nik' => '7372010101900002',
            'full_name' => 'Penduduk Duplikat',
            'gender' => Gender::LAKI_LAKI->value,
            'birth_place' => 'Parepare',
            'birth_date' => '1990-01-01',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::BELUM_KAWIN->value,
            'family_relation' => FamilyRelation::ANAK->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'kk_id' => $kk2->id,
        ]);
    }

    public function test_edit_penduduk_allows_same_nik_but_rejects_conflicting_nik(): void
    {
        $education = Education::firstOrCreate(['name' => 'SMA']);
        $religion = Religion::firstOrCreate(['name' => 'Islam']);
        $occupation = Occupation::firstOrCreate(['name' => 'Buruh']);

        $kk = KartuKeluarga::factory()->create();
        $service = app(PendudukKkService::class);

        $p1 = $service->save([
            'nik' => '7372010101900003',
            'full_name' => 'Penduduk Satu',
            'gender' => Gender::LAKI_LAKI->value,
            'birth_place' => 'Parepare',
            'birth_date' => '1990-01-01',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::KAWIN->value,
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'kk_id' => $kk->id,
        ]);

        $p2 = $service->save([
            'nik' => '7372010101900004',
            'full_name' => 'Penduduk Dua',
            'gender' => Gender::PEREMPUAN->value,
            'birth_place' => 'Parepare',
            'birth_date' => '1992-02-02',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::KAWIN->value,
            'family_relation' => FamilyRelation::ISTRI->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'kk_id' => $kk->id,
        ]);

        // Editing p1 with same NIK succeeds
        $updated = $service->save([
            'nik' => '7372010101900003',
            'full_name' => 'Penduduk Satu Updated',
            'gender' => Gender::LAKI_LAKI->value,
            'birth_place' => 'Parepare',
            'birth_date' => '1990-01-01',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::KAWIN->value,
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'kk_id' => $kk->id,
        ], $p1);

        $this->assertSame('Penduduk Satu Updated', $updated->full_name);

        // Editing p1 to use p2's NIK must fail
        $this->expectException(ValidationException::class);

        $service->save([
            'nik' => '7372010101900004',
            'full_name' => 'Penduduk Satu Conflicted',
            'gender' => Gender::LAKI_LAKI->value,
            'birth_place' => 'Parepare',
            'birth_date' => '1990-01-01',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::KAWIN->value,
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'kk_id' => $kk->id,
        ], $p1);
    }

    public function test_kk_zero_member_lifecycle_and_delete_protection(): void
    {
        $kk = KartuKeluarga::factory()->create(['kk_number' => '7372010101010099']);
        $p = Penduduk::factory()->create(['kk_id' => $kk->id]);

        $this->assertSame(1, $kk->jumlah_anggota);

        // Delete the member
        $p->delete();

        $kk->refresh();
        $this->assertSame(0, $kk->jumlah_anggota);
        $this->assertTrue(KartuKeluarga::where('id', $kk->id)->exists());

        // KK exists with 0 members. If it has history, guard blocks deletion
        // If it has no history, assertDeletable passes
        $this->assertNull(KartuKeluargaDeleteGuard::assertDeletable($kk));
    }

    public function test_ocr_header_extracts_postal_code(): void
    {
        $text = <<<'TXT'
NOMOR KARTU KELUARGA : 7372010101230001
ALAMAT : JL. VETERAN NO. 10
RT/RW : 002/001
KELURAHAN : MALLUSETASI
KECAMATAN : UJUNG
KABUPATEN/KOTA : KOTA PAREPARE
KODE POS : 91111
PROVINSI : SULAWESI SELATAN

NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN STATUS PERKAWINAN STATUS HUBUNGAN DALAM KELUARGA
1 AHMAD 7372010101900010 LAKI-LAKI PAREPARE 01-01-1990 ISLAM SMA WIRASWASTA KAWIN KEPALA KELUARGA
TXT;

        $parser = app(OcrParsingService::class);
        $result = $parser->parse($text, 95.0);

        $this->assertSame('7372010101230001', $result->kkNumber);
        $this->assertSame('91111', $result->postalCode);
        $this->assertSame('002', $result->rt);
        $this->assertSame('001', $result->rw);
        $this->assertCount(1, $result->members);
    }

    public function test_save_ocr_members_preserves_existing_resident_fields_when_ocr_is_partial(): void
    {
        $education = Education::firstOrCreate(['name' => 'SMA']);
        $religion = Religion::firstOrCreate(['name' => 'Islam']);
        $occupation = Occupation::firstOrCreate(['name' => 'Wiraswasta']);

        $kk = KartuKeluarga::factory()->create();
        $service = app(PendudukKkService::class);

        // Pre-create resident with complete details
        $existing = $service->save([
            'nik' => '7372010101800099',
            'full_name' => 'Andi Suryaman',
            'gender' => Gender::LAKI_LAKI->value,
            'birth_place' => 'Parepare',
            'birth_date' => '1980-01-10',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::KAWIN->value,
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'kk_id' => $kk->id,
        ]);

        // Partial OCR only has NIK, Nama, Education; but occupation, religion, marital, relation are blank
        $saved = $service->saveOcrMembers($kk, [
            [
                'nik' => '7372010101800099',
                'full_name' => 'Andi Suryaman Updated',
                'gender' => '',
                'birth_place' => '',
                'birth_date' => null,
                'religion' => null,
                'education' => 'SMA',
                'occupation' => null, // Omitted in OCR
                'marital_status' => null,
                'family_relation' => null,
            ],
        ]);

        $this->assertCount(1, $saved);
        $this->assertSame('Andi Suryaman Updated', $saved[0]->full_name);
        // Preserved from existing
        $this->assertSame($occupation->id, $saved[0]->occupation_id);
        $this->assertSame($religion->id, $saved[0]->religion_id);
        $this->assertSame(Gender::LAKI_LAKI, $saved[0]->gender);
        $this->assertSame(MaritalStatus::KAWIN, $saved[0]->marital_status);
        $this->assertSame(FamilyRelation::KEPALA_KELUARGA, $saved[0]->family_relation);
    }

    public function test_save_ocr_members_rolls_back_entirely_when_any_member_fails(): void
    {
        $education = Education::firstOrCreate(['name' => 'SMA']);
        $religion = Religion::firstOrCreate(['name' => 'Islam']);
        $occupation = Occupation::firstOrCreate(['name' => 'Buruh']);

        $kk = KartuKeluarga::factory()->create();
        $service = app(PendudukKkService::class);

        try {
            $service->saveOcrMembers($kk, [
                [
                    'nik' => '7372010101900088',
                    'full_name' => 'Member Valid',
                    'gender' => 'LAKI_LAKI',
                    'birth_place' => 'Parepare',
                    'birth_date' => '1990-01-01',
                    'religion' => 'ISLAM',
                    'education' => 'SMA',
                    'occupation' => 'BURUH',
                    'marital_status' => 'KAWIN',
                    'family_relation' => 'KEPALA_KELUARGA',
                ],
                [
                    'nik' => 'INVALID_NIK', // Will fail 16-digit validation
                    'full_name' => 'Member Invalid',
                    'gender' => 'PEREMPUAN',
                    'birth_place' => 'Parepare',
                    'birth_date' => '1992-01-01',
                    'religion' => 'ISLAM',
                    'education' => 'SMA',
                    'occupation' => 'BURUH',
                    'marital_status' => 'KAWIN',
                    'family_relation' => 'ISTRI',
                ],
            ]);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            // First member must NOT have been saved
            $this->assertFalse(Penduduk::where('nik', '7372010101900088')->exists());
        }
    }

    public function test_marriage_scenario_moves_resident_without_duplicate_person(): void
    {
        $education = Education::firstOrCreate(['name' => 'SMA']);
        $religion = Religion::firstOrCreate(['name' => 'Islam']);
        $occupation = Occupation::firstOrCreate(['name' => 'Wiraswasta']);

        $kkParents = KartuKeluarga::factory()->create(['kk_number' => '7372010101011111']);
        $kkNewFamily = KartuKeluarga::factory()->create(['kk_number' => '7372010101012222']);

        $service = app(PendudukKkService::class);

        // Person A in parents KK as ANAK (Belum Kawin)
        $person = $service->save([
            'nik' => '7372010101950001',
            'full_name' => 'Andi Pratama',
            'gender' => Gender::LAKI_LAKI->value,
            'birth_place' => 'Parepare',
            'birth_date' => '1995-05-10',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::BELUM_KAWIN->value,
            'family_relation' => FamilyRelation::ANAK->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'kk_id' => $kkParents->id,
        ]);

        $this->assertSame(1, Penduduk::where('nik', '7372010101950001')->count());
        $this->assertSame(1, $kkParents->fresh()->jumlah_anggota);
        $this->assertSame(0, $kkNewFamily->fresh()->jumlah_anggota);

        // Person A marries and becomes Kepala Keluarga in KK Baru
        $updatedPerson = $service->save([
            'nik' => '7372010101950001',
            'full_name' => 'Andi Pratama',
            'gender' => Gender::LAKI_LAKI->value,
            'birth_place' => 'Parepare',
            'birth_date' => '1995-05-10',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::KAWIN->value,
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'kk_id' => $kkNewFamily->id,
        ], $person);

        // Assertions: Exactly ONE person in DB, membership moved, history recorded
        $this->assertSame(1, Penduduk::where('nik', '7372010101950001')->count());
        $this->assertSame($person->id, $updatedPerson->id);
        $this->assertSame($kkNewFamily->id, $updatedPerson->kk_id);
        $this->assertSame(MaritalStatus::KAWIN, $updatedPerson->marital_status);
        $this->assertSame(FamilyRelation::KEPALA_KELUARGA, $updatedPerson->family_relation);

        $this->assertSame(0, $kkParents->fresh()->jumlah_anggota);
        $this->assertSame(1, $kkNewFamily->fresh()->jumlah_anggota);
    }

    public function test_divorce_scenario_updates_status_without_duplicate_person(): void
    {
        $education = Education::firstOrCreate(['name' => 'SMA']);
        $religion = Religion::firstOrCreate(['name' => 'Islam']);
        $occupation = Occupation::firstOrCreate(['name' => 'Wiraswasta']);

        $kk = KartuKeluarga::factory()->create();
        $service = app(PendudukKkService::class);

        $person = $service->save([
            'nik' => '7372010101950002',
            'full_name' => 'Siti Nurhaliza',
            'gender' => Gender::PEREMPUAN->value,
            'birth_place' => 'Parepare',
            'birth_date' => '1995-08-15',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::KAWIN->value,
            'family_relation' => FamilyRelation::ISTRI->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'kk_id' => $kk->id,
        ]);

        // Divorce event
        $updated = $service->save([
            'nik' => '7372010101950002',
            'full_name' => 'Siti Nurhaliza',
            'gender' => Gender::PEREMPUAN->value,
            'birth_place' => 'Parepare',
            'birth_date' => '1995-08-15',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::CERAI_HIDUP->value,
            'family_relation' => FamilyRelation::LAINNYA->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'kk_id' => $kk->id,
        ], $person);

        $this->assertSame(1, Penduduk::where('nik', '7372010101950002')->count());
        $this->assertSame(MaritalStatus::CERAI_HIDUP, $updated->marital_status);
    }

    public function test_current_vs_history_kpi_counts_never_double_counts(): void
    {
        $education = Education::firstOrCreate(['name' => 'SMA']);
        $religion = Religion::firstOrCreate(['name' => 'Islam']);
        $occupation = Occupation::firstOrCreate(['name' => 'Wiraswasta']);

        $kk1 = KartuKeluarga::factory()->create();
        $kk2 = KartuKeluarga::factory()->create();

        $service = app(PendudukKkService::class);

        // Person created in KK 1
        $person = $service->save([
            'nik' => '7372010101950003',
            'full_name' => 'Budi Santoso',
            'gender' => Gender::LAKI_LAKI->value,
            'birth_place' => 'Parepare',
            'birth_date' => '1990-01-01',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::BELUM_KAWIN->value,
            'family_relation' => FamilyRelation::ANAK->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'kk_id' => $kk1->id,
        ]);

        // Person moves to KK 2
        $service->save([
            'nik' => '7372010101950003',
            'full_name' => 'Budi Santoso',
            'gender' => Gender::LAKI_LAKI->value,
            'birth_place' => 'Parepare',
            'birth_date' => '1990-01-01',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::KAWIN->value,
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'kk_id' => $kk2->id,
        ], $person);

        // Person status changed to PINDAH then back to ACTIVE
        $person->update(['resident_status' => ResidentStatus::PINDAH]);
        PendudukStatusHistory::create([
            'penduduk_id' => $person->id,
            'status' => ResidentStatus::PINDAH,
            'recorded_at' => now(),
        ]);

        $person->update(['resident_status' => ResidentStatus::ACTIVE]);
        PendudukStatusHistory::create([
            'penduduk_id' => $person->id,
            'status' => ResidentStatus::ACTIVE,
            'recorded_at' => now(),
        ]);

        // Total active penduduk count is 1
        $this->assertSame(1, Penduduk::where('resident_status', ResidentStatus::ACTIVE->value)->count());
        $this->assertSame(1, Penduduk::where('nik', '7372010101950003')->count());

        // KK 1 active members = 0, KK 2 active members = 1
        $this->assertSame(0, $kk1->fresh()->jumlah_anggota);
        $this->assertSame(1, $kk2->fresh()->jumlah_anggota);

        // History count is >= 2, but current person count is strictly 1
        $this->assertGreaterThanOrEqual(2, $person->statusHistories()->count());
    }

    public function test_parsed_ocr_result_is_valid_method(): void
    {
        $validResult = new ParsedOcrResult(
            confidence: 90.0,
            lowConfidence: false,
            kkNumber: '3207122801160001',
            address: 'JL. RAYA SUKAMAJU',
            rt: '001',
            rw: '002',
            lingkungan: null,
            members: [],
            warnings: [],
            validationErrors: [],
            durationMs: 12.5,
            postalCode: '46100',
        );

        $this->assertTrue($validResult->isValid());
        $this->assertFalse($validResult->isEmpty());

        $invalidResult = new ParsedOcrResult(
            confidence: 50.0,
            lowConfidence: true,
            kkNumber: null,
            address: null,
            rt: null,
            rw: null,
            lingkungan: null,
            members: [],
            warnings: ['Low confidence'],
            validationErrors: ['KK number missing'],
            durationMs: 15.0,
            postalCode: null,
        );

        $this->assertFalse($invalidResult->isValid());
        $this->assertTrue($invalidResult->isEmpty());
    }
}
