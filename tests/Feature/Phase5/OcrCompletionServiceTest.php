<?php

namespace Tests\Feature\Phase5;

use App\Enums\OcrJobStatus;
use App\Enums\OcrOutcome;
use App\Models\AuditLog;
use App\Models\KartuKeluarga;
use App\Models\OcrJob;
use App\Models\Rt;
use App\Models\User;
use App\Services\OcrCompletionResult;
use App\Services\OcrCompletionService;
use App\Services\OcrImportService;
use App\Services\OcrParsingService;
use App\Services\OcrReviewService;
use App\Services\PendudukImportService;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 5.9 — OCR finalization.
 *
 * Proves OcrCompletionService closes the OCR lifecycle after a successful
 * import: the job reaches the persisted COMPLETED state, the completion
 * timestamp + import summary + final processing metrics land on the audit
 * snapshot, an audit-log entry is appended, and the pipeline's transient
 * `ocr_temp` artifacts are cleaned up. Duplicate completion is refused
 * (idempotence), a failed job-update step rolls the whole finalization back,
 * and non-finalizable jobs are rejected by the guard.
 *
 * Fixtures are built by actually running the Phase 5.7 KK import and the
 * Phase 5.8 Penduduk import first, so the job state (outcome SAVED, kk_id
 * linked, extracted_data snapshot with the Penduduk marker) matches
 * production exactly.
 */
class OcrCompletionServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Complete, parseable KK scan (same fixture as 5.5 / 5.7 / 5.8). */
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

    private OcrCompletionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('ocr_temp');

        $this->service = new OcrCompletionService(app(FilesystemManager::class));
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
     * Run Phase 5.7's real KK import and Phase 5.8's real Penduduk import to
     * produce the fully imported job the finalization consumes.
     */
    private function fullyImportedJob(): OcrJob
    {
        Rt::factory()->create(['number' => '01']);

        $job = $this->reviewableJob();

        (new OcrImportService(new OcrParsingService, new OcrReviewService))
            ->import($job, $this->corrections());

        (new PendudukImportService(new OcrParsingService, new OcrReviewService))
            ->import($job->fresh());

        return $job->fresh();
    }

    /** Seed a couple of transient preprocessed intermediates on ocr_temp. */
    private function seedTempArtifacts(): void
    {
        Storage::disk('ocr_temp')->put('preprocessed-1.png', 'fake png bytes');
        Storage::disk('ocr_temp')->put('nested/preprocessed-2.png', 'fake png bytes');
    }

    public function test_successful_finalize_marks_job_completed(): void
    {
        $job = $this->fullyImportedJob();

        $result = $this->service->finalize($job);

        $this->assertInstanceOf(OcrCompletionResult::class, $result);
        $this->assertTrue($result->isCompleted());

        // In-memory and persisted: the job reached the COMPLETED state.
        $this->assertSame(OcrJobStatus::COMPLETED, $job->status);
        $this->assertSame(OcrJobStatus::COMPLETED, $job->fresh()->status);
        $this->assertDatabaseHas('ocr_jobs', ['id' => $job->id, 'status' => OcrJobStatus::COMPLETED->value]);
    }

    public function test_completion_summary_and_processing_metrics_generated(): void
    {
        $job = $this->fullyImportedJob();

        $this->service->finalize($job);

        $data = $job->fresh()->extracted_data;

        $this->assertArrayHasKey('ocr_completed_at', $data);
        $this->assertArrayHasKey('completion_summary', $data);
        $this->assertArrayHasKey('processing_metrics', $data);

        // Import summary generation.
        $summary = $data['completion_summary'];
        $this->assertTrue($summary['imported']);
        $this->assertSame('3207122801160001', $summary['kk_number']);
        $this->assertSame($job->kk_id, $summary['kartu_keluarga_id']);
        $this->assertSame(3, $summary['member_count']);
        $this->assertSame(3, $summary['penduduk_count']);
        $this->assertArrayHasKey('completed_at', $summary);

        // Final processing metrics.
        $metrics = $data['processing_metrics'];
        $this->assertSame(OcrJobStatus::SUCCESS->value, $metrics['ocr_status']);
        $this->assertSame(92.5, (float) $metrics['confidence']);
        $this->assertIsInt($metrics['duration_ms']);
        $this->assertGreaterThan(0, $metrics['word_count']);
        $this->assertSame(3, $metrics['member_count']);
        $this->assertSame(3, $metrics['imported_penduduk_count']);
    }

    public function test_completion_timestamp_recorded_without_overwriting_finished_at(): void
    {
        $job = $this->fullyImportedJob();
        $finishedAt = $job->fresh()->finished_at;

        $this->service->finalize($job);

        // The OCR finished_at (set at extraction) is untouched; the
        // completion gets its own timestamp on the audit snapshot.
        $this->assertTrue($job->fresh()->finished_at->equalTo($finishedAt));
        $this->assertNotNull($job->fresh()->extracted_data['ocr_completed_at']);
    }

    public function test_audit_log_entry_appended_with_operator(): void
    {
        $job = $this->fullyImportedJob();
        $operator = User::factory()->create();

        $this->service->finalize($job, $operator);

        $entry = AuditLog::query()
            ->where('loggable_type', $job->getMorphClass())
            ->where('loggable_id', $job->id)
            ->where('event', 'ocr.completed')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($operator->id, $entry->actor_id);
        $this->assertSame('3207122801160001', $entry->new_values['kk_number']);
        $this->assertSame(3, $entry->new_values['penduduk_count']);
        $this->assertSame(92.5, (float) $entry->new_values['confidence']);
    }

    public function test_result_dto_reports_completed_details(): void
    {
        $job = $this->fullyImportedJob();

        $result = $this->service->finalize($job);

        $this->assertTrue($result->isCompleted());
        $this->assertFalse($result->isAlreadyCompleted());
        $this->assertSame($job->id, $result->jobId);
        $this->assertSame($job->kk_id, $result->kartuKeluargaId);
        $this->assertSame('3207122801160001', $result->kkNumber);
        $this->assertSame(3, $result->importedPendudukCount);
        $this->assertSame('3207122801160001', $result->summary['kk_number']);
        $this->assertSame(OcrJobStatus::SUCCESS->value, $result->metrics['ocr_status']);
    }

    public function test_duplicate_completion_is_refused_without_duplicate_writes(): void
    {
        $job = $this->fullyImportedJob();

        $first = $this->service->finalize($job);
        $second = $this->service->finalize($job->fresh());

        $this->assertTrue($first->isCompleted());
        $this->assertTrue($second->isAlreadyCompleted());

        // Exactly one completion: one audit entry, snapshot written once.
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('loggable_type', $job->getMorphClass())
                ->where('loggable_id', $job->id)
                ->where('event', 'ocr.completed')
                ->count(),
        );
        $this->assertDatabaseHas('ocr_jobs', ['id' => $job->id, 'status' => OcrJobStatus::COMPLETED->value]);

        // The imported family is untouched — no duplicate writes.
        $this->assertDatabaseCount('penduduk', 3);
        $this->assertDatabaseCount('kk_anggota', 3);
    }

    public function test_transaction_rolls_back_when_job_update_fails(): void
    {
        $job = $this->fullyImportedJob();
        $this->seedTempArtifacts();

        $failing = new class(app(FilesystemManager::class)) extends OcrCompletionService
        {
            protected function markJobCompleted(OcrJob $job, array $summary, array $metrics, ?User $operator): void
            {
                throw new RuntimeException('simulated completion marker failure');
            }
        };

        try {
            $failing->finalize($job);
            $this->fail('Expected a RuntimeException from the failing job-save step.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated completion marker failure', $e->getMessage());
        }

        // The completion rolled back: no COMPLETED state, no audit entry, no
        // snapshot markers.
        $this->assertNotSame(OcrJobStatus::COMPLETED, $job->fresh()->status);
        $this->assertSame(OcrOutcome::SAVED->value, $job->fresh()->outcome);
        $this->assertNull($job->fresh()->extracted_data['ocr_completed_at'] ?? null);
        $this->assertArrayNotHasKey('completion_summary', $job->fresh()->extracted_data);
        $this->assertSame(
            0,
            AuditLog::query()
                ->where('loggable_type', $job->getMorphClass())
                ->where('loggable_id', $job->id)
                ->where('event', 'ocr.completed')
                ->count(),
        );

        // Cleanup runs only after a successful persistence — the transient
        // files survive the failed finalization.
        $this->assertNotEmpty(Storage::disk('ocr_temp')->allFiles());
    }

    public function test_guard_rejects_job_without_penduduk_import(): void
    {
        // Phase 5.7 KK import only — the Penduduk import (Phase 5.8) has not
        // run, so the job is not finalizable.
        Rt::factory()->create(['number' => '01']);

        $job = $this->reviewableJob();

        (new OcrImportService(new OcrParsingService, new OcrReviewService))
            ->import($job, $this->corrections());

        try {
            $this->service->finalize($job->fresh());
            $this->fail('Expected an InvalidArgumentException for a KK-only (not Penduduk-imported) job.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be finalized', $e->getMessage());
        }

        $this->assertNotSame(OcrJobStatus::COMPLETED, $job->fresh()->status);
    }

    public function test_guard_rejects_not_yet_imported_job(): void
    {
        $job = $this->reviewableJob();

        try {
            $this->service->finalize($job);
            $this->fail('Expected an InvalidArgumentException for a not-yet-imported job.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be finalized', $e->getMessage());
        }

        $this->assertNotSame(OcrJobStatus::COMPLETED, $job->fresh()->status);
    }

    public function test_guard_rejects_failed_job(): void
    {
        $job = OcrJob::create([
            'kk_id' => null,
            'source_image_hash' => hash('sha256', fake()->unique()->uuid()),
            'source_image_path' => 'ocr/'.fake()->unique()->uuid().'.jpg',
            'status' => OcrJobStatus::FAILED,
            'error_message' => 'simulated engine failure',
            'raw_text' => null,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        try {
            $this->service->finalize($job);
            $this->fail('Expected an InvalidArgumentException for a FAILED job.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be finalized', $e->getMessage());
        }

        $this->assertSame(OcrJobStatus::FAILED, $job->fresh()->status);
    }

    public function test_cleanup_of_transient_processing_artifacts(): void
    {
        $job = $this->fullyImportedJob();
        $this->seedTempArtifacts();
        $this->assertNotEmpty(Storage::disk('ocr_temp')->allFiles());

        $result = $this->service->finalize($job);

        $this->assertTrue($result->isCompleted());
        $this->assertSame([], Storage::disk('ocr_temp')->allFiles());
        $this->assertSame([], Storage::disk('ocr_temp')->allDirectories());

        // The uploaded source document archive is NOT a pipeline temp — it
        // must survive finalization untouched.
        $this->assertSame(
            1,
            KartuKeluarga::where('kk_number', '3207122801160001')->count(),
        );
    }
}
