<?php

namespace Tests\Feature\Phase5;

use App\Enums\OcrJobStatus;
use App\Enums\OcrOutcome;
use App\Models\KartuKeluarga;
use App\Models\OcrJob;
use App\Models\User;
use App\Services\OcrImportResult;
use App\Services\OcrImportService;
use App\Services\OcrParsingService;
use App\Services\OcrReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 5.7 — Import Kartu Keluarga.
 *
 * Proves OcrImportService is the operator-triggered "SIMPAN" write (ADR-009:
 * OCR is an assistant; the Service layer persists only after explicit
 * approval). A validated review result creates exactly one KartuKeluarga
 * record, duplicate KK numbers are rejected, the write is transactional
 * (a failed job update rolls the KK insert back), and a successful import
 * updates the OCR job (outcome SAVED, kk_id, reviewed_at, operator, approved
 * data snapshot). No Penduduk / KkAnggota rows are ever created here.
 *
 * Note: jobs are created with OcrJob::create() rather than the OcrJob factory
 * — the factory definition eagerly creates a backing KartuKeluarga when the
 * table is empty (its kk_id default is `… ?? KartuKeluarga::factory()->create()
 * ->id`), which would skew the KK-count assertions in this suite.
 */
class OcrImportServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Complete, parseable KK scan (same fixture as 5.5). */
    private const KK_TEXT = <<<'TXT'
NOMOR KARTU KELUARGA : 3207122801160001
NAMA KEPALA KELUARGA : BUDI SANTOSO
ALAMAT : JL. MELATI NO. 5
RT/RW : 001/004
KODE POS : 16340

NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN STATUS PERKAWINAN STATUS HUBUNGAN DALAM KELUARGA
1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA
2 SITI AMINAH 3207124501010002 PEREMPUAN TANETE 05-04-2018 ISLAM SLTA/SEDERAJAT IBU RUMAH TANGGA KAWIN ISTRI
3 Andi Prasetyo 3207121503050003 LAKI-LAKI BOGOR 15-03-2005 ISLAM SMP PELAJAR/MAHASISWA BELUM KAWIN ANAK
TXT;

    private OcrImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OcrImportService(
            new OcrParsingService,
            new OcrReviewService,
        );
    }

    /** A finished, reviewable job (SUCCESS + raw text) linked to no KK. */
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

    /** The effective approved dataset the review form would submit. */
    private function validCorrections(): array
    {
        return (new OcrReviewService)
            ->validate((new OcrParsingService)->parse(self::KK_TEXT, 92.5))
            ->correctedData();
    }

    public function test_successful_import_creates_one_kartu_keluarga(): void
    {
        $job = $this->reviewableJob();

        $result = $this->service->import($job, $this->validCorrections());

        $this->assertInstanceOf(OcrImportResult::class, $result);
        $this->assertTrue($result->isSaved());
        $this->assertSame('3207122801160001', $result->kkNumber);

        $kk = KartuKeluarga::findOrFail($result->kartuKeluargaId);
        $this->assertSame('3207122801160001', $kk->kk_number);
        $this->assertSame('JL. MELATI NO. 5', $kk->address);
        $this->assertSame(1, KartuKeluarga::count());
        // This phase creates ONLY the KK record.
        $this->assertDatabaseCount('penduduk', 0);
        $this->assertDatabaseCount('kk_anggota', 0);
    }

    public function test_duplicate_kk_number_is_rejected_without_writes(): void
    {
        KartuKeluarga::factory()->create(['kk_number' => '3207122801160001']);
        $job = $this->reviewableJob();

        $result = $this->service->import($job, $this->validCorrections());

        $this->assertTrue($result->isDuplicate());
        $this->assertSame('3207122801160001', $result->kkNumber);
        $this->assertSame(1, KartuKeluarga::count());

        $job->refresh();
        $this->assertNull($job->kk_id);
        $this->assertNull($job->outcome);
        $this->assertNull($job->reviewed_at);
    }

    public function test_transaction_rolls_back_when_job_update_fails(): void
    {
        $job = $this->reviewableJob();

        $failing = new class(new OcrParsingService, new OcrReviewService) extends OcrImportService
        {
            protected function markJobSaved(OcrJob $job, KartuKeluarga $kartuKeluarga, ?User $operator, array $data): void
            {
                throw new RuntimeException('simulated job-save failure');
            }
        };

        try {
            $failing->import($job, $this->validCorrections());
            $this->fail('Expected a RuntimeException from the failing job-save step.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated job-save failure', $e->getMessage());
        }

        // The KK insert was rolled back with the failed job update — no orphan
        // KartuKeluarga row, and the job itself stays untouched.
        $this->assertDatabaseCount('kartu_keluarga', 0);
        $job->refresh();
        $this->assertNull($job->kk_id);
        $this->assertNull($job->outcome);
    }

    public function test_ocr_job_is_updated_after_successful_import(): void
    {
        $job = $this->reviewableJob();

        $result = $this->service->import($job, $this->validCorrections());

        $job->refresh();
        $this->assertSame($result->kartuKeluargaId, $job->kk_id);
        // outcome has no model cast yet (Phase 2 model); the stored value is
        // the enum's string form.
        $this->assertSame(OcrOutcome::SAVED->value, $job->outcome);
        $this->assertNotNull($job->reviewed_at);
        $this->assertSame('3207122801160001', $job->extracted_data['kk_number']);
        $this->assertCount(3, $job->extracted_data['members']);
        $this->assertNull($job->operator_id);
    }

    public function test_operator_is_recorded_when_provided(): void
    {
        $operator = User::factory()->create();

        $this->service->import($this->reviewableJob(), $this->validCorrections(), $operator);

        $this->assertDatabaseHas('ocr_jobs', [
            'kk_id' => KartuKeluarga::firstOrFail()->id,
            'operator_id' => $operator->id,
            'status' => OcrJobStatus::SUCCESS->value,
        ]);
    }

    public function test_invalid_data_fails_import_without_writes(): void
    {
        $job = $this->reviewableJob();

        $result = $this->service->import($job, array_replace(
            $this->validCorrections(),
            ['kk_number' => '123'],
        ));

        $this->assertTrue($result->isInvalid());
        $this->assertArrayHasKey('kk_number', $result->errors);
        $this->assertSame(0, KartuKeluarga::count());

        $job->refresh();
        $this->assertNull($job->kk_id);
        $this->assertNull($job->outcome);
    }

    public function test_already_saved_job_is_rejected_without_writes(): void
    {
        $kk = KartuKeluarga::factory()->create();
        $job = OcrJob::create([
            'kk_id' => $kk->id,
            'source_image_hash' => hash('sha256', fake()->unique()->uuid()),
            'source_image_path' => 'ocr/'.fake()->unique()->uuid().'.jpg',
            'status' => OcrJobStatus::SUCCESS,
            'confidence' => 92.5,
            'raw_text' => self::KK_TEXT,
            'outcome' => OcrOutcome::SAVED,
            'reviewed_at' => now(),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $result = $this->service->import($job, $this->validCorrections());

        $this->assertTrue($result->isAlreadySaved());
        $this->assertSame(1, KartuKeluarga::count());
        $this->assertSame($kk->id, $job->fresh()->kk_id);
    }

    public function test_non_reviewable_job_is_rejected_by_the_guard(): void
    {
        $job = OcrJob::create([
            'kk_id' => null,
            'source_image_hash' => hash('sha256', fake()->unique()->uuid()),
            'source_image_path' => 'ocr/'.fake()->unique()->uuid().'.jpg',
            'status' => OcrJobStatus::PENDING,
            'raw_text' => self::KK_TEXT,
            'started_at' => now(),
        ]);

        try {
            $this->service->import($job, $this->validCorrections());
            $this->fail('Expected an InvalidArgumentException for a non-reviewable job.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be imported', $e->getMessage());
        }

        $this->assertSame(0, KartuKeluarga::count());
    }
}
