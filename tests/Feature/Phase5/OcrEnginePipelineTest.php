<?php

namespace Tests\Feature\Phase5;

use App\Enums\OcrJobStatus;
use App\Exceptions\OcrEngineException;
use App\Models\OcrJob;
use App\Services\ImagePreprocessor;
use App\Services\KkDocumentUploadService;
use App\Services\OcrEngine;
use App\Services\OcrProcessingService;
use App\Services\OcrResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\FakeOcrEngine;
use Tests\TestCase;

/**
 * Phase 5.4 — OCR engine integration through the pipeline.
 *
 * Drives OcrProcessingService::extract() (after start()) with a FakeOcrEngine
 * bound in the container, proving the job status updates and raw extracted
 * text are persisted on the existing ocr_jobs schema, and that engine
 * failures and timeouts land the job in FAILED.
 */
class OcrEnginePipelineTest extends TestCase
{
    use RefreshDatabase;

    private OcrProcessingService $service;

    private FakeOcrEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new FakeOcrEngine;
        $this->app->instance(OcrEngine::class, $this->engine);
        $this->service = app(OcrProcessingService::class);

        Storage::fake(KkDocumentUploadService::DISK);
        Storage::fake(ImagePreprocessor::DISK);
    }

    public function test_successful_ocr_persists_success_with_raw_text_and_confidence(): void
    {
        $job = $this->uploadAndStart();
        $this->engine->result = new OcrResult("3207122801160001 BUDI SANTOSO\nKEPALA KELUARGA", 92.5, 4, 150.0);

        $this->service->extract($job);

        $this->assertSame(OcrJobStatus::SUCCESS, $job->fresh()->status);
        $this->assertSame("3207122801160001 BUDI SANTOSO\nKEPALA KELUARGA", $job->fresh()->raw_text);
        $this->assertSame('92.50', $job->fresh()->confidence);
        $this->assertNotNull($job->fresh()->finished_at);

        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id,
            'status' => OcrJobStatus::SUCCESS->value,
        ]);

        // Engine ran on the preprocessed image from the ocr_temp disk.
        $this->assertStringEndsWith('-preprocessed.png', $this->engine->lastImagePath);
        $this->assertInstanceOf(OcrResult::class, $this->service->ocrResult());
        $this->assertSame(92.5, $this->service->ocrResult()->confidence);
    }

    public function test_low_confidence_ocr_persists_low_confidence_status(): void
    {
        $job = $this->uploadAndStart();
        $this->engine->result = new OcrResult('blurry text', 45.0, 2, 80.0);

        $this->service->extract($job);

        $fresh = $job->fresh();
        $this->assertSame(OcrJobStatus::LOW_CONFIDENCE, $fresh->status);
        $this->assertSame('blurry text', $fresh->raw_text);
        $this->assertSame('45.00', $fresh->confidence);
        $this->assertNotNull($fresh->finished_at);
    }

    public function test_threshold_boundary_confidence_is_success(): void
    {
        $job = $this->uploadAndStart();
        $this->engine->result = new OcrResult('ok', 70.0, 1, 10.0);

        $this->service->extract($job);

        $this->assertSame(OcrJobStatus::SUCCESS, $job->fresh()->status);
    }

    public function test_empty_ocr_result_persists_low_confidence_without_text(): void
    {
        $job = $this->uploadAndStart();
        $this->engine->result = new OcrResult('', 0.0, 0, 12.0);

        $this->service->extract($job);

        $fresh = $job->fresh();
        $this->assertSame(OcrJobStatus::LOW_CONFIDENCE, $fresh->status);
        $this->assertSame('', $fresh->raw_text);
        $this->assertSame('0.00', $fresh->confidence);
        $this->assertNotNull($fresh->finished_at);
    }

    public function test_engine_failure_marks_job_failed(): void
    {
        $job = $this->uploadAndStart();
        $this->engine->exception = new OcrEngineException('Tesseract failed: Error opening data file /usr/share/tesseract-ocr/tessdata/ind.traineddata');

        try {
            $this->service->extract($job);
            $this->fail('Expected OcrEngineException to propagate.');
        } catch (OcrEngineException $e) {
            $this->assertStringContainsString('Tesseract failed', $e->getMessage());
        }

        $failed = $job->fresh();
        $this->assertSame(OcrJobStatus::FAILED, $failed->status);
        $this->assertStringContainsString('Tesseract failed', (string) $failed->error_message);
        $this->assertNotNull($failed->finished_at);
        $this->assertNull($failed->raw_text);

        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id,
            'status' => OcrJobStatus::FAILED->value,
        ]);
    }

    public function test_timeout_marks_job_failed(): void
    {
        $job = $this->uploadAndStart();
        $this->engine->exception = new OcrEngineException('OCR timed out after 10 seconds.');

        try {
            $this->service->extract($job);
            $this->fail('Expected OcrEngineException for a timeout.');
        } catch (OcrEngineException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }

        $failed = $job->fresh();
        $this->assertSame(OcrJobStatus::FAILED, $failed->status);
        $this->assertStringContainsString('timed out', (string) $failed->error_message);
        $this->assertNotNull($failed->finished_at);
        $this->assertNull($failed->raw_text);
    }

    public function test_database_status_updates_through_pipeline(): void
    {
        $job = $this->uploadAndStart();
        $this->engine->result = new OcrResult('text', 90.0, 2, 5.0);

        // After start() the DB row is still PENDING (PROCESSING is not
        // persistable); it reaches a terminal SUCCESS only after extract().
        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id,
            'status' => OcrJobStatus::PENDING->value,
        ]);

        $this->service->extract($job);

        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id,
            'status' => OcrJobStatus::SUCCESS->value,
        ]);
    }

    public function test_extract_without_start_is_rejected(): void
    {
        // A job moved to PROCESSING without a preprocessing result (start()
        // never ran on this instance) must be rejected before extraction.
        $job = OcrJob::factory()->create(['status' => OcrJobStatus::PENDING]);
        $job->status = OcrJobStatus::PROCESSING;

        try {
            $this->service->extract($job);
            $this->fail('Expected InvalidArgumentException when extraction runs without start().');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('start()', $e->getMessage());
        }

        // Nothing persisted: the DB row is still the original PENDING.
        $this->assertSame(OcrJobStatus::PENDING, $job->fresh()->status);
    }

    public function test_extract_on_non_processing_job_is_rejected(): void
    {
        $job = OcrJob::factory()->create(['status' => OcrJobStatus::SUCCESS]);

        try {
            $this->service->extract($job);
            $this->fail('Expected InvalidArgumentException for a non-PROCESSING job.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('must be PROCESSING', $e->getMessage());
        }

        $this->assertSame(OcrJobStatus::SUCCESS, $job->fresh()->status);
    }

    private function uploadAndStart(): OcrJob
    {
        $job = app(KkDocumentUploadService::class)->upload(UploadedFile::fake()->image('kk-scan.png', 800, 600));
        $this->service->start($job);

        return $job;
    }
}
