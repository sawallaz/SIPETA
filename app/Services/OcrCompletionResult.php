<?php

namespace App\Services;

use App\Models\OcrJob;

/**
 * Result of an OCR workflow finalization attempt (Phase 5.9).
 *
 * Produced by {@see OcrCompletionService::finalize()}: the outcome of
 * finalizing a fully imported OCR job (Phase 5.7 KartuKeluarga + Phase 5.8
 * Penduduk) into the terminal COMPLETED state. Purely an in-memory value
 * object returned to the caller — it is never persisted and carries no side
 * effects.
 *
 * Statuses:
 * - `completed`         — the job transitioned to COMPLETED; the completion
 *                         summary + final processing metrics were persisted
 *                         onto the job's extracted-data snapshot and an audit
 *                         entry was appended.
 * - `already_completed` — the job was already in the COMPLETED state;
 *                         nothing was written (idempotence — no duplicate
 *                         completion).
 */
final readonly class OcrCompletionResult
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ALREADY_COMPLETED = 'already_completed';

    /**
     * @param  array<string, mixed>  $summary  import summary (kk_number,
     *                                         kartu_keluarga_id, member_count,
     *                                         penduduk_count, completed_at)
     * @param  array<string, mixed>  $metrics  final processing metrics
     *                                         (ocr_status, confidence,
     *                                         duration_ms, word_count,
     *                                         member_count,
     *                                         imported_penduduk_count)
     */
    public function __construct(
        public string $status,
        public ?int $jobId = null,
        public ?int $kartuKeluargaId = null,
        public ?string $kkNumber = null,
        public ?int $importedPendudukCount = null,
        public array $summary = [],
        public array $metrics = [],
    ) {}

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $metrics
     */
    public static function completed(OcrJob $job, array $summary, array $metrics): self
    {
        return new self(
            self::STATUS_COMPLETED,
            $job->id,
            $job->kk_id,
            isset($summary['kk_number']) ? (string) $summary['kk_number'] : null,
            isset($summary['penduduk_count']) ? (int) $summary['penduduk_count'] : null,
            $summary,
            $metrics,
        );
    }

    /**
     * @param  array<string, mixed>|null  $extractedData  the job's audit
     *                                                    snapshot (already
     *                                                    carries the original
     *                                                    completion summary)
     */
    public static function alreadyCompleted(?array $extractedData = null): self
    {
        $summary = is_array($extractedData['completion_summary'] ?? null) ? $extractedData['completion_summary'] : [];

        return new self(self::STATUS_ALREADY_COMPLETED, summary: $summary);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isAlreadyCompleted(): bool
    {
        return $this->status === self::STATUS_ALREADY_COMPLETED;
    }
}
