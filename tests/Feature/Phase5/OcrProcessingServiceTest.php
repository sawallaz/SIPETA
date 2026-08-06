<?php

namespace Tests\Feature\Phase5;

use App\Enums\OcrJobStatus;
use App\Exceptions\OcrProcessingException;
use App\Models\OcrJob;
use App\Models\User;
use App\Services\KkDocumentUploadService;
use App\Services\OcrProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 5.2 — OCR processing pipeline foundation.
 *
 * Proves the OcrProcessingService starts a PENDING job (transitioning it to
 * the PROCESSING runtime state), loads and validates the uploaded source
 * image, persists the terminal FAILED state when processing cannot continue,
 * and rejects jobs that are not startable.
 *
 * PROCESSING is deliberately an in-memory state (the ocr_jobs.status column
 * constraint predates it — see OcrJobStatus::persistable()); the DB records
 * PENDING and the terminal FAILED state.
 */
class OcrProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    private OcrProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OcrProcessingService::class);
        Storage::fake(KkDocumentUploadService::DISK);
    }

    public function test_pending_job_transitions_to_processing(): void
    {
        $operator = User::factory()->create();
        $file = UploadedFile::fake()->image('kk-scan.jpg');
        $job = app(KkDocumentUploadService::class)->upload($file, $operator);

        $result = $this->service->start($job);

        $this->assertSame(OcrJobStatus::PROCESSING, $result->status);
        $this->assertSame($job->id, $result->id);
    }

    public function test_pending_job_stays_pending_in_database_during_processing(): void
    {
        $job = app(KkDocumentUploadService::class)->upload(UploadedFile::fake()->image('kk-scan.png'));

        $this->service->start($job);

        // PROCESSING cannot be persisted yet: the column constraint predates
        // the value, so the DB row must remain PENDING while it is processed.
        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id,
            'status' => OcrJobStatus::PENDING->value,
        ]);
    }

    public function test_missing_source_image_fails_the_job(): void
    {
        $job = OcrJob::factory()->create([
            'status' => OcrJobStatus::PENDING,
            'source_image_path' => 'ocr/missing.jpg',
        ]);

        try {
            $this->service->start($job);
            $this->fail('Expected OcrProcessingException for a missing source image.');
        } catch (OcrProcessingException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }

        $failed = $job->fresh();
        $this->assertSame(OcrJobStatus::FAILED, $failed->status);
        $this->assertNotNull($failed->error_message);
        $this->assertNotNull($failed->finished_at);

        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id,
            'status' => OcrJobStatus::FAILED->value,
        ]);
    }

    public function test_unreadable_source_image_fails_the_job(): void
    {
        Storage::disk(KkDocumentUploadService::DISK)->put('ocr/empty.jpg', '');

        $job = OcrJob::factory()->create([
            'status' => OcrJobStatus::PENDING,
            'source_image_path' => 'ocr/empty.jpg',
        ]);

        try {
            $this->service->start($job);
            $this->fail('Expected OcrProcessingException for an unreadable source image.');
        } catch (OcrProcessingException $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }

        $this->assertSame(OcrJobStatus::FAILED, $job->fresh()->status);
    }

    public function test_non_image_source_fails_the_job(): void
    {
        Storage::disk(KkDocumentUploadService::DISK)->put('ocr/fake.jpg', 'not an image at all');

        $job = OcrJob::factory()->create([
            'status' => OcrJobStatus::PENDING,
            'source_image_path' => 'ocr/fake.jpg',
        ]);

        try {
            $this->service->start($job);
            $this->fail('Expected OcrProcessingException for a non-image source file.');
        } catch (OcrProcessingException $e) {
            $this->assertStringContainsString('not a supported image', $e->getMessage());
        }

        $this->assertSame(OcrJobStatus::FAILED, $job->fresh()->status);
    }

    public function test_non_pending_job_is_rejected_without_state_change(): void
    {
        $job = OcrJob::factory()->create([
            'status' => OcrJobStatus::SUCCESS,
        ]);

        try {
            $this->service->start($job);
            $this->fail('Expected InvalidArgumentException for a non-PENDING job.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('must be PENDING', $e->getMessage());
        }

        $fresh = $job->fresh();
        $this->assertSame(OcrJobStatus::SUCCESS, $fresh->status);
        $this->assertNull($fresh->error_message);
        $this->assertNull($fresh->finished_at);

        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id,
            'status' => OcrJobStatus::SUCCESS->value,
        ]);
    }

    public function test_failed_status_is_persisted_with_failure_details(): void
    {
        $job = OcrJob::factory()->create([
            'status' => OcrJobStatus::PENDING,
            'source_image_path' => 'ocr/absent.jpg',
        ]);

        try {
            $this->service->start($job);
        } catch (OcrProcessingException) {
            // expected — the failure itself is asserted via the DB row
        }

        $failed = $job->fresh();
        $this->assertSame(OcrJobStatus::FAILED, $failed->status);
        $this->assertStringContainsString('absent.jpg', (string) $failed->error_message);
        $this->assertNotNull($failed->finished_at);

        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id,
            'status' => OcrJobStatus::FAILED->value,
            'error_message' => $failed->error_message,
        ]);
    }
}
