<?php

namespace Tests\Feature\Phase5;

use App\Enums\BloodType;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\KkAnggotaStatus;
use App\Enums\MaritalStatus;
use App\Enums\OcrJobStatus;
use App\Enums\OcrOutcome;
use App\Enums\ResidentStatus;
use App\Models\KartuKeluarga;
use App\Models\KkAnggota;
use App\Models\OcrJob;
use App\Models\Penduduk;
use App\Models\Rt;
use App\Models\User;
use App\Services\OcrParsingService;
use App\Services\OcrReviewService;
use App\Services\PendudukImportResult;
use App\Services\PendudukImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 5.8 — Import Penduduk.
 *
 * Proves PendudukImportService persists the approved OCR review members
 * (Phase 5.7's `extracted_data` snapshot) as Penduduk rows under the KK Phase
 * 5.7 already created: one Penduduk row (+ one ACTIVE KkAnggota membership)
 * per family member, linked to the KK, preserving the parsed family relation.
 * Duplicate NIKs are rejected, the whole write is transactional (a failed job
 * update rolls the family back), and a successful import records the
 * penduduk import on the OCR job's audit snapshot.
 *
 * Fixtures are built by actually running the Phase 5.7 import service first,
 * so the job state (outcome SAVED, kk_id linked, extracted_data snapshot)
 * matches production exactly.
 */
class PendudukImportServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Complete, parseable KK scan (same fixture as 5.5 / 5.7). */
    private const KK_TEXT = <<<'TXT'
NOMOR KARTU KELUARGA : 3207122801160001
NAMA KEPALA KELUARGA : BUDI SANTOSO
ALAMAT : JL. MELATI NO. 5
RT/RW : 001/004
KODE POS : 16340

NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN STATUS PERKAWINAN STATUS HUBUNGAN DALAM KELUARGA
1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA
2 SITI AMINAH 3207134501010002 PEREMPUAN TANETE 05-04-2018 ISLAM SLTA/SEDERAJAT IBU RUMAH TANGGA KAWIN ISTRI
3 Andi Prasetyo 3207141503050003 LAKI-LAKI BOGOR 15-03-2005 ISLAM SMP PELAJAR/MAHASISWA BELUM KAWIN ANAK
TXT;

    private PendudukImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PendudukImportService(
            new OcrParsingService,
            new OcrReviewService,
        );
    }

    /** The effective approved dataset the Phase 5.6 review would submit. */
    private function corrections(): array
    {
        return (new OcrReviewService)
            ->validate((new OcrParsingService)->parse(self::KK_TEXT, 92.5))
            ->correctedData();
    }

    /** A finished, reviewable job (SUCCESS + raw text) linked to no KK yet. */
    private function reviewableJob(): OcrJob
    {
        return OcrJob::create([
            'kk_id' => null,
            'source_image_hash' => hash('sha256', fake()->unique()->uuid()),
            'source_image_path' => 'ocr/'.fake()->unique()->uuid().'.jpg',
            'status' => OcrJobStatus::SUCCESS,
            'confidence' => 92.5,
            'raw_text' => self::KK_TEXT,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    /**
     * Build the Phase 5.7 saved KK + job state that the Penduduk import
     * consumes. The Phase 5.7 service is intentionally removed with the
     * retired OCR review workflow, so this fixture mirrors its persisted
     * contract directly.
     *
     * @return array{0: OcrJob, 1: KartuKeluarga}
     */
    private function savedKartuKeluarga(bool $withRt = true): array
    {
        if ($withRt) {
            Rt::factory()->create(['number' => '01']);
        }

        $job = $this->reviewableJob();

        $data = $this->corrections();
        $kartuKeluarga = KartuKeluarga::create([
            'kk_number' => $data['kk_number'],
            'address' => $data['address'],
        ]);

        $job->update([
            'kk_id' => $kartuKeluarga->id,
            'outcome' => OcrOutcome::SAVED->value,
            'reviewed_at' => now(),
            'extracted_data' => $data,
        ]);

        return [$job->fresh(), $kartuKeluarga->fresh()];
    }

    public function test_successful_import_creates_all_family_members(): void
    {
        [$job, $kk] = $this->savedKartuKeluarga();

        $result = $this->service->import($job);

        $this->assertInstanceOf(PendudukImportResult::class, $result);
        $this->assertTrue($result->isSaved());
        $this->assertSame($kk->id, $result->kartuKeluargaId);
        $this->assertSame($kk->kk_number, $result->kkNumber);
        $this->assertSame(3, $result->importedCount);

        $this->assertDatabaseCount('penduduk', 3);
        $this->assertDatabaseCount('kk_anggota', 3);
    }

    public function test_each_member_is_linked_to_the_imported_kartu_keluarga(): void
    {
        [$job, $kk] = $this->savedKartuKeluarga();

        $this->service->import($job);

        $this->assertSame(3, Penduduk::where('kk_id', $kk->id)->count());
        $this->assertSame(3, $kk->penduduks()->count());

        $rt = Rt::where('number', '01')->firstOrFail();

        foreach ($kk->penduduks as $penduduk) {
            $this->assertSame($kk->id, $penduduk->kk_id);
            $this->assertSame($kk->id, $penduduk->kartuKeluarga->id);
            $this->assertSame($rt->id, $penduduk->rt_id);
        }
    }

    public function test_parsed_family_relation_is_preserved_on_penduduk_and_kk_membership(): void
    {
        [$job, $kk] = $this->savedKartuKeluarga();

        $this->service->import($job);

        $head = Penduduk::where('nik', '3207122801160001')->firstOrFail();
        $spouse = Penduduk::where('nik', '3207134501010002')->firstOrFail();
        $child = Penduduk::where('nik', '3207141503050003')->firstOrFail();

        // Family relation preserved on the resident row...
        $this->assertSame(FamilyRelation::KEPALA_KELUARGA, $head->family_relation);
        $this->assertSame(FamilyRelation::ISTRI, $spouse->family_relation);
        $this->assertSame(FamilyRelation::ANAK, $child->family_relation);

        // ...and mirrored onto the active KkAnggota membership rows.
        $memberships = KkAnggota::where('kk_id', $kk->id)->get()->keyBy('penduduk_id');

        $this->assertSame(FamilyRelation::KEPALA_KELUARGA, $memberships[$head->id]->family_relation);
        $this->assertSame(FamilyRelation::ISTRI, $memberships[$spouse->id]->family_relation);
        $this->assertSame(FamilyRelation::ANAK, $memberships[$child->id]->family_relation);

        foreach ($memberships as $membership) {
            $this->assertSame(KkAnggotaStatus::AKTIF, $membership->status);
            $this->assertNotNull($membership->effective_date);
        }
    }

    public function test_member_fields_map_onto_the_existing_domain(): void
    {
        [$job, $kk] = $this->savedKartuKeluarga();

        $this->service->import($job);

        $head = Penduduk::where('nik', '3207122801160001')->firstOrFail();

        $this->assertSame('BUDI SANTOSO', $head->full_name);
        $this->assertSame(Gender::LAKI_LAKI, $head->gender);
        $this->assertSame('TANETE', $head->birth_place);
        $this->assertSame('2016-01-28', $head->birth_date->toDateString());
        $this->assertSame(MaritalStatus::KAWIN, $head->marital_status);
        $this->assertSame(BloodType::TIDAK_DIKETAHUI, $head->blood_type);
        $this->assertSame(ResidentStatus::ACTIVE, $head->resident_status);

        // Lookup masters are resolved (created when absent) to the query.
        $this->assertSame('Islam', $head->religion->name);
        $this->assertSame('Slta/Sederajat', $head->education->name);
        $this->assertSame('Buruh Harian Lepas', $head->occupation->name);
    }

    public function test_duplicate_nik_against_existing_penduduk_is_rejected_without_writes(): void
    {
        [$job, $kk] = $this->savedKartuKeluarga();
        // An already-created resident holds the head's NIK.
        Penduduk::factory()->create(['nik' => '3207122801160001']);

        $result = $this->service->import($job);

        $this->assertTrue($result->isDuplicate());
        $this->assertSame('3207122801160001', $result->duplicateNik);
        $this->assertDatabaseCount('penduduk', 1); // only the pre-existing one
        $this->assertDatabaseCount('kk_anggota', 0);
        $this->assertNull($job->fresh()->extracted_data['penduduk_imported_at'] ?? null);
    }

    public function test_duplicate_nik_within_approved_list_is_rejected_without_writes(): void
    {
        [$job, $kk] = $this->savedKartuKeluarga();

        $data = $job->extracted_data;
        $data['members'][1]['nik'] = $data['members'][0]['nik']; // 2nd member repeats NIK 1
        $job->update(['extracted_data' => $data]);
        $job->refresh();

        $result = $this->service->import($job);

        $this->assertTrue($result->isDuplicate());
        $this->assertSame('3207122801160001', $result->duplicateNik);
        $this->assertDatabaseCount('penduduk', 0);
        $this->assertDatabaseCount('kk_anggota', 0);
    }

    public function test_transaction_rolls_back_when_job_update_fails(): void
    {
        [$job, $kk] = $this->savedKartuKeluarga();

        $failing = new class(new OcrParsingService, new OcrReviewService) extends PendudukImportService
        {
            protected function markJobImported(OcrJob $job, array $pendudukIds, ?User $operator): void
            {
                throw new RuntimeException('simulated penduduk-import marker failure');
            }
        };

        try {
            $failing->import($job);
            $this->fail('Expected a RuntimeException from the failing job-save step.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated penduduk-import marker failure', $e->getMessage());
        }

        // The Penduduk + KkAnggota inserts were rolled back — no orphan family.
        $this->assertDatabaseCount('penduduk', 0);
        $this->assertDatabaseCount('kk_anggota', 0);
        $this->assertNull($job->fresh()->extracted_data['penduduk_imported_at'] ?? null);
    }

    public function test_ocr_job_is_updated_after_successful_import(): void
    {
        [$job, $kk] = $this->savedKartuKeluarga();

        $operator = User::factory()->create();
        $this->service->import($job, $operator);

        $data = $job->fresh()->extracted_data;
        $this->assertArrayHasKey('penduduk_imported_at', $data);
        $this->assertCount(3, $data['penduduk_ids']);
        $this->assertSame($operator->id, $data['penduduk_operator_id']);
        $this->assertSame($kk->id, $job->fresh()->kk_id);
        $this->assertSame(OcrOutcome::SAVED->value, $job->fresh()->outcome);
    }

    public function test_already_imported_job_is_rejected_without_duplicate_writes(): void
    {
        [$job, $kk] = $this->savedKartuKeluarga();

        $this->service->import($job);
        $result = $this->service->import($job->fresh());

        $this->assertTrue($result->isAlreadyImported());
        $this->assertDatabaseCount('penduduk', 3);
        $this->assertDatabaseCount('kk_anggota', 3);
    }

    public function test_invalid_snapshot_fails_import_without_writes(): void
    {
        [$job, $kk] = $this->savedKartuKeluarga();

        $data = $job->extracted_data;
        unset($data['members']);
        $job->update(['extracted_data' => $data]);
        $job->refresh();

        $result = $this->service->import($job);

        $this->assertTrue($result->isInvalid());
        $this->assertArrayHasKey('members', $result->errors);
        $this->assertDatabaseCount('penduduk', 0);
        $this->assertDatabaseCount('kk_anggota', 0);
    }

    public function test_import_fails_when_no_rt_matches_the_reviewed_rt(): void
    {
        [$job, $kk] = $this->savedKartuKeluarga(withRt: false);

        $result = $this->service->import($job);

        $this->assertTrue($result->isInvalid());
        $this->assertArrayHasKey('rt', $result->errors);
        $this->assertDatabaseCount('penduduk', 0);
        $this->assertDatabaseCount('kk_anggota', 0);
    }

    public function test_non_saved_job_is_rejected_by_the_guard(): void
    {
        $job = $this->reviewableJob();

        try {
            $this->service->import($job);
            $this->fail('Expected an InvalidArgumentException for a not-yet-imported job.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('imported KartuKeluarga', $e->getMessage());
        }

        $this->assertDatabaseCount('penduduk', 0);
    }
}
