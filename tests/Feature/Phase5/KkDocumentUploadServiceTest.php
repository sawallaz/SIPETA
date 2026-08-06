<?php

namespace Tests\Feature\Phase5;

use App\Enums\OcrJobStatus;
use App\Models\OcrJob;
use App\Models\User;
use App\Services\KkDocumentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 5.1 — OCR upload foundation.
 *
 * Proves the KkDocumentUploadService accepts valid KK documents, rejects
 * invalid extensions and oversized files, and stores accepted uploads on
 * the private kk_uploads disk with a PENDING ocr_jobs record.
 */
class KkDocumentUploadServiceTest extends TestCase
{
    use RefreshDatabase;

    private KkDocumentUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(KkDocumentUploadService::class);
        Storage::fake(KkDocumentUploadService::DISK);
    }

    public function test_valid_jpeg_upload_is_accepted_and_registers_pending_job(): void
    {
        $operator = User::factory()->create();
        $file = UploadedFile::fake()->image('kk-scan.jpg');

        $job = $this->service->upload($file, $operator);

        $this->assertInstanceOf(OcrJob::class, $job);
        $this->assertSame(OcrJobStatus::PENDING, $job->status);
        $this->assertNull($job->kk_id);
        $this->assertSame($operator->id, $job->operator_id);
        $this->assertNotNull($job->source_image_path);
        $this->assertNotNull($job->started_at);

        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id,
            'status' => OcrJobStatus::PENDING->value,
        ]);
    }

    public function test_valid_png_upload_is_accepted(): void
    {
        $file = UploadedFile::fake()->image('kk-scan.png');

        $job = $this->service->upload($file);

        $this->assertSame(OcrJobStatus::PENDING, $job->status);
        $this->assertStringEndsWith('.png', $job->source_image_path);
    }

    public function test_upload_is_stored_correctly_on_private_disk(): void
    {
        $file = UploadedFile::fake()->image('kk-scan.jpg');
        $expectedHash = hash_file('sha256', (string) $file->getRealPath());

        $job = $this->service->upload($file);

        Storage::disk(KkDocumentUploadService::DISK)->assertExists($job->source_image_path);

        $storedBytes = Storage::disk(KkDocumentUploadService::DISK)->get($job->source_image_path);
        $this->assertSame($expectedHash, hash('sha256', (string) $storedBytes));
        $this->assertSame($expectedHash, $job->source_image_hash);

        $this->assertSame('private', config('filesystems.disks.kk_uploads.visibility'));
        $this->assertSame(storage_path('app/kk_uploads'), config('filesystems.disks.kk_uploads.root'));
    }

    public function test_invalid_extension_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('kk-document.txt', 10, 'text/plain');

        try {
            $this->service->upload($file);
            $this->fail('Expected ValidationException for a .txt upload.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document', $e->errors());
        }

        $this->assertDatabaseCount('ocr_jobs', 0);
        Storage::disk(KkDocumentUploadService::DISK)->assertDirectoryEmpty('');
    }

    public function test_oversized_file_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('kk-scan.jpg', 6000, 'image/jpeg');

        try {
            $this->service->upload($file);
            $this->fail('Expected ValidationException for a file larger than 5 MB.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document', $e->errors());
        }

        $this->assertDatabaseCount('ocr_jobs', 0);
        Storage::disk(KkDocumentUploadService::DISK)->assertDirectoryEmpty('');
    }

    public function test_wrong_content_with_allowed_extension_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('kk-scan.jpg', 10, 'text/plain');

        try {
            $this->service->upload($file);
            $this->fail('Expected ValidationException for a text file disguised as a JPEG.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document', $e->errors());
        }

        $this->assertDatabaseCount('ocr_jobs', 0);
        Storage::disk(KkDocumentUploadService::DISK)->assertDirectoryEmpty('');
    }
}
