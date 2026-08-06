<?php

namespace App\Services;

use App\Enums\OcrJobStatus;
use App\Exceptions\OcrEngineException;
use App\Exceptions\OcrProcessingException;
use App\Models\OcrJob;
use Illuminate\Filesystem\FilesystemManager;
use InvalidArgumentException;

/**
 * OCR processing pipeline (Phase 5.2 + 5.3 + 5.4).
 *
 * Two sequential stages, each its own method:
 *
 *   start(OcrJob)   — validate the PENDING job, move it to the PROCESSING
 *                     runtime state, load the uploaded source image from the
 *                     private kk_uploads disk, validate processing
 *                     prerequisites, and run image preprocessing (5.3).
 *   extract(OcrJob) — run the OCR engine over the preprocessed image and
 *                     persist the outcome: SUCCESS / LOW_CONFIDENCE with
 *                     confidence + raw_text + finished_at, or FAILED with
 *                     error_message when the engine fails or times out (5.4).
 *   parse(OcrJob)   — convert the extracted raw text into a structured
 *                     ParsedOcrResult (KK number, address, RT/RW/lingkungan,
 *                     member rows). Strictly in-memory: nothing is persisted
 *                     (5.5).
 *
 * PROCESSING is an in-memory state only: the ocr_jobs.status column
 * constraint (SQLite CHECK / MySQL ENUM, from the Phase 2 migration) predates
 * the PROCESSING value, so it cannot be persisted — the DB row stays PENDING
 * until a terminal state (SUCCESS / LOW_CONFIDENCE / FAILED) is persisted.
 *
 * The preprocessing, engine, and parse results of the last run are exposed
 * via preprocessResult() / ocrResult() / parsedResult() (in-memory only,
 * never persisted). No database mapping happens here — the review sub-phase
 * consumes the parsed result and persists only after the operator saves.
 */
class OcrProcessingService
{
    /** JPEG magic bytes (FF D8 FF). */
    private const JPEG_SIGNATURE = "\xFF\xD8\xFF";

    /** PNG magic bytes (89 50 4E 47 0D 0A 1A 0A). */
    private const PNG_SIGNATURE = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";

    private ?PreprocessResult $preprocessResult = null;

    private ?OcrResult $ocrResult = null;

    private ?ParsedOcrResult $parsedResult = null;

    public function __construct(
        private readonly FilesystemManager $filesystem,
        private readonly ImagePreprocessor $preprocessor,
        private readonly OcrEngine $engine,
        private readonly OcrParsingService $parsingService,
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
     * Run the OCR engine over the preprocessed image and persist the job
     * outcome. A job must already be in the PROCESSING runtime state with a
     * preprocessing result (i.e. start() must have run on this instance).
     *
     * @throws InvalidArgumentException when the job is not in PROCESSING
     *                                  state or preprocessing has not run
     * @throws OcrEngineException when the engine fails or times out; the job
     *                            is persisted as FAILED first
     */
    public function extract(OcrJob $job): OcrJob
    {
        $this->assertExtractable($job);

        try {
            $imagePath = $this->filesystem->disk(ImagePreprocessor::DISK)->path($this->preprocessResult->path);
            $this->ocrResult = $this->engine->run($imagePath);
        } catch (OcrEngineException $e) {
            $this->markFailed($job, $e->getMessage());

            throw $e;
        }

        $this->persistOutcome($job, $this->ocrResult);

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
     * Parse the extracted raw text into a structured result (Phase 5.5).
     *
     * A job must already be in a terminal extracted state (SUCCESS or
     * LOW_CONFIDENCE) with an engine result (i.e. extract() must have run on
     * this instance). Parsing is strictly in-memory: nothing is persisted —
     * the review sub-phase consumes the returned ParsedOcrResult to
     * pre-populate the operator form (ADR-009: OCR is an assistant).
     *
     * @throws InvalidArgumentException when the job is not in a terminal
     *                                  extracted state or extraction has not
     *                                  run
     */
    public function parse(OcrJob $job): ParsedOcrResult
    {
        $this->assertParsable($job);

        $this->parsedResult = $this->parsingService->parse(
            $this->ocrResult->rawText,
            $this->ocrResult->confidence,
        );

        return $this->parsedResult;
    }

    /**
     * OCR result of the last extract() run — raw text + mean confidence +
     * word count + duration (in-memory only). Null until extract() has run
     * successfully.
     */
    public function ocrResult(): ?OcrResult
    {
        return $this->ocrResult;
    }

    /**
     * Structured parse result of the last parse() run (in-memory only, never
     * persisted). Null until parse() has run successfully.
     */
    public function parsedResult(): ?ParsedOcrResult
    {
        return $this->parsedResult;
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
     * Reject jobs that are not extractable (must be in the PROCESSING runtime
     * state with a preprocessing result available).
     *
     * @throws InvalidArgumentException
     */
    private function assertExtractable(OcrJob $job): void
    {
        if ($job->status !== OcrJobStatus::PROCESSING) {
            throw new InvalidArgumentException(
                sprintf('OCR job %d cannot be extracted: status must be PROCESSING, got %s.', $job->id, $job->status->value)
            );
        }

        if ($this->preprocessResult === null) {
            throw new InvalidArgumentException(
                sprintf('OCR job %d cannot be extracted: preprocessing has not run (call start() first).', $job->id)
            );
        }
    }

    /**
     * Reject jobs that are not parsable (must be in a terminal extracted
     * state with an engine result available).
     *
     * @throws InvalidArgumentException
     */
    private function assertParsable(OcrJob $job): void
    {
        if ($job->status !== OcrJobStatus::SUCCESS && $job->status !== OcrJobStatus::LOW_CONFIDENCE) {
            throw new InvalidArgumentException(
                sprintf('OCR job %d cannot be parsed: status must be SUCCESS or LOW_CONFIDENCE, got %s.', $job->id, $job->status->value)
            );
        }

        if ($this->ocrResult === null) {
            throw new InvalidArgumentException(
                sprintf('OCR job %d cannot be parsed: extraction has not run (call extract() first).', $job->id)
            );
        }
    }

    /**
     * Persist the terminal outcome of a successful extraction.
     *
     * Mean confidence >= the configured threshold yields SUCCESS, otherwise
     * LOW_CONFIDENCE (including empty/no-word results). Raw text and
     * confidence are stored on the existing ocr_jobs columns — no schema
     * change.
     */
    private function persistOutcome(OcrJob $job, OcrResult $result): void
    {
        $threshold = (float) config('ocr.confidence_threshold', 70);

        $job->status = $result->confidence >= $threshold
            ? OcrJobStatus::SUCCESS
            : OcrJobStatus::LOW_CONFIDENCE;
        $job->confidence = $result->confidence;
        $job->raw_text = $result->rawText;
        $job->finished_at = now();
        $job->save();
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
