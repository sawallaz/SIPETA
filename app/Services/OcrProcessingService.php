<?php

namespace App\Services;

use App\Enums\OcrJobStatus;
use App\Exceptions\OcrProcessingException;
use App\Models\OcrJob;
use Illuminate\Filesystem\FilesystemManager;
use InvalidArgumentException;

/**
 * OCR processing pipeline (Phase 5.2 + 5.3).
 *
 * Starts processing a PENDING OCR job: validates the job, transitions it to
 * the PROCESSING runtime state, loads the uploaded source image from the
 * private kk_uploads disk, validates processing prerequisites, and runs the
 * image preprocessing stage (Phase 5.3 — ImagePreprocessor) on it.
 *
 * PROCESSING is an in-memory state only: the ocr_jobs.status column
 * constraint (SQLite CHECK / MySQL ENUM, from the Phase 2 migration) predates
 * the PROCESSING value, so it cannot be persisted yet. The DB records PENDING
 * (created by the upload service) and the terminal FAILED state — set with
 * error_message and finished_at when processing cannot continue.
 *
 * Actual OCR extraction is a later sub-phase; this service only prepares the
 * job for it. The preprocessing result of the last run is exposed via
 * preprocessResult() (in-memory only, never persisted).
 */
class OcrProcessingService
{
    /** JPEG magic bytes (FF D8 FF). */
    private const JPEG_SIGNATURE = "\xFF\xD8\xFF";

    /** PNG magic bytes (89 50 4E 47 0D 0A 1A 0A). */
    private const PNG_SIGNATURE = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";

    private ?PreprocessResult $preprocessResult = null;

    public function __construct(
        private readonly FilesystemManager $filesystem,
        private readonly ImagePreprocessor $preprocessor,
    ) {}

    /**
     * Start processing a PENDING OCR job: load the source image, validate
     * processing prerequisites, and run image preprocessing.
     *
     * @throws InvalidArgumentException when the job is not in PENDING state
     * @throws OcrProcessingException when the source image cannot be loaded
     *                                or preprocessed; the job is persisted as
     *                                FAILED first
     */
    public function start(OcrJob $job): OcrJob
    {
        $this->assertStartable($job);

        $job->status = OcrJobStatus::PROCESSING;

        try {
            $bytes = $this->loadUploadedImage($job);
            $this->preprocessResult = $this->preprocessor->preprocess($bytes, $job->source_image_path);
        } catch (OcrProcessingException $e) {
            $this->markFailed($job, $e->getMessage());

            throw $e;
        }

        return $job;
    }

    /**
     * Preprocessing result of the last start() run — tracking metadata only
     * (path, dimensions, brightness, transforms, warnings, duration). Never
     * persisted; null until start() has run successfully.
     */
    public function preprocessResult(): ?PreprocessResult
    {
        return $this->preprocessResult;
    }

    /**
     * Reject jobs that are not startable.
     *
     * @throws InvalidArgumentException
     */
    private function assertStartable(OcrJob $job): void
    {
        if ($job->status !== OcrJobStatus::PENDING) {
            throw new InvalidArgumentException(
                sprintf('OCR job %d cannot be started: status must be PENDING, got %s.', $job->id, $job->status->value)
            );
        }
    }

    /**
     * Load the uploaded source image from the kk_uploads disk and validate
     * that processing prerequisites are met (file exists, non-empty, and a
     * supported image format).
     *
     * @throws OcrProcessingException
     */
    private function loadUploadedImage(OcrJob $job): string
    {
        $disk = $this->filesystem->disk(KkDocumentUploadService::DISK);
        $path = $job->source_image_path;

        if (! $disk->exists($path)) {
            throw new OcrProcessingException(
                sprintf('Source image for OCR job %d not found on disk: %s.', $job->id, $path)
            );
        }

        $bytes = $disk->get($path);

        if ($bytes === null || $bytes === '') {
            throw new OcrProcessingException(
                sprintf('Source image for OCR job %d is empty or unreadable: %s.', $job->id, $path)
            );
        }

        if (! $this->isSupportedImage($bytes)) {
            throw new OcrProcessingException(
                sprintf('Source image for OCR job %d is not a supported image (JPEG/PNG): %s.', $job->id, $path)
            );
        }

        return $bytes;
    }

    /**
     * Validate the image signature without decoding the image itself
     * (no OCR, no parsing — format validation only).
     */
    private function isSupportedImage(string $bytes): bool
    {
        return str_starts_with($bytes, self::JPEG_SIGNATURE)
            || str_starts_with($bytes, self::PNG_SIGNATURE);
    }

    /**
     * Persist the terminal FAILED state with failure details.
     */
    private function markFailed(OcrJob $job, string $message): void
    {
        $job->status = OcrJobStatus::FAILED;
        $job->error_message = $message;
        $job->finished_at = now();
        $job->save();
    }
}
