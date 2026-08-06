<?php

namespace App\Services;

use App\Enums\OcrJobStatus;
use App\Enums\OcrOutcome;
use App\Models\AuditLog;
use App\Models\KartuKeluarga;
use App\Models\OcrJob;
use App\Models\User;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Finalize a fully imported OCR job (Phase 5.9).
 *
 * The operator-triggered completion step that closes the OCR lifecycle
 * (ADR-009 — OCR is an assistant; the Service layer persists only after the
 * operator approved the import). Once Phase 5.7 created the KartuKeluarga
 * and Phase 5.8 imported the family members, this service:
 *
 * - transitions the job to the terminal COMPLETED state (the status column
 *   was widened by the Phase 5.9 migration),
 * - records the completion timestamp on the job's audit snapshot
 *   (`extracted_data.ocr_completed_at`),
 * - generates the import summary + final processing metrics on the same
 *   snapshot (no schema change — the JSON column already exists),
 * - appends an audit-log entry (the project's existing morphic audit trail),
 * - cleans up the OCR pipeline's transient artifacts (the `ocr_temp` disk
 *   that holds preprocessed intermediates).
 *
 * Guarantees:
 * - **Idempotence** — a job already in the COMPLETED state returns
 *   `already_completed` and writes nothing (no duplicate completion).
 * - **Transactional write** — the job update (status + summary + metrics)
 *   and the audit entry happen in one DB transaction; a failed job update
 *   rolls the whole finalization back (no completion without audit, no
 *   half-written snapshot).
 * - **Best-effort cleanup** — transient files are removed AFTER the
 *   completion is persisted; a cleanup failure is logged as a warning and
 *   never rolls back (or breaks) the completion.
 * - **Mutation guard** — jobs that have not been fully imported (no SAVED
 *   outcome, no Penduduk-import marker) throw `InvalidArgumentException`,
 *   matching the pipeline conventions.
 *
 * This class is intentionally not `final` and {@see markJobCompleted()} is
 * `protected` so rollback behaviour can be verified by a test subclass that
 * makes the job-save step fail.
 */
class OcrCompletionService
{
    public function __construct(
        private readonly FilesystemManager $filesystem,
    ) {}

    /**
     * Finalize a fully imported OCR job into the terminal COMPLETED state.
     *
     * @param  User|null  $operator  the operator finalizing the job (recorded
     *                               on the audit-log entry)
     * @return OcrCompletionResult the outcome — never throws for business
     *                             decisions (already completed), but rethrows
     *                             a fatal error from the write step
     *
     * @throws InvalidArgumentException when the job has not been fully
     *                                  imported (Phase 5.7 + 5.8) and so
     *                                  cannot be finalized
     */
    public function finalize(OcrJob $job, ?User $operator = null): OcrCompletionResult
    {
        $startedAt = microtime(true);

        if ($job->status === OcrJobStatus::COMPLETED) {
            return OcrCompletionResult::alreadyCompleted($job->extracted_data);
        }

        $this->assertFinalizable($job);

        $summary = $this->buildSummary($job);
        $metrics = $this->buildMetrics($job);

        try {
            DB::transaction(function () use ($job, $summary, $metrics, $operator): void {
                $this->markJobCompleted($job, $summary, $metrics, $operator);
            });
        } catch (Throwable $e) {
            $this->log('failure', $job, $startedAt, $e);

            throw $e;
        }

        $this->cleanupTempArtifacts();

        $this->log('completed', $job, $startedAt);

        return OcrCompletionResult::completed($job, $summary, $metrics);
    }

    /**
     * Reject jobs that are not finalizable: the full import must have run —
     * the job carries the Phase 5.7 SAVED outcome with a linked KK and raw
     * text, and the Phase 5.8 Penduduk-import marker on the snapshot.
     *
     * @throws InvalidArgumentException
     */
    private function assertFinalizable(OcrJob $job): void
    {
        $extracted = is_array($job->extracted_data) ? $job->extracted_data : [];

        $fullyImported = in_array($job->status, [OcrJobStatus::SUCCESS, OcrJobStatus::LOW_CONFIDENCE], true)
            && $job->outcome === OcrOutcome::SAVED->value
            && $job->kk_id !== null
            && filled($job->raw_text)
            && isset($extracted['penduduk_imported_at']);

        if (! $fullyImported) {
            throw new InvalidArgumentException(
                sprintf(
                    'OCR job %d cannot be finalized: a fully imported job (outcome %s + kk_id + Penduduk import) is required, got status %s, outcome %s, kk_id %s.',
                    $job->id,
                    OcrOutcome::SAVED->value,
                    $job->status?->value ?? 'unknown',
                    $job->outcome ?? 'null',
                    $job->kk_id ?? 'null',
                )
            );
        }
    }

    /**
     * Import summary: what the completed OCR run produced — the linked KK,
     * the number of approved family members and the Penduduk rows created by
     * Phase 5.8, plus the completion timestamp.
     *
     * @return array<string, mixed>
     */
    private function buildSummary(OcrJob $job): array
    {
        $data = is_array($job->extracted_data) ? $job->extracted_data : [];
        $members = $data['members'] ?? [];
        $pendudukIds = $data['penduduk_ids'] ?? [];
        $kk = $job->kk_id !== null ? KartuKeluarga::find($job->kk_id) : null;

        return [
            'imported' => true,
            'kk_number' => $kk?->kk_number ?? ($data['kk_number'] ?? null),
            'kartu_keluarga_id' => $job->kk_id,
            'member_count' => is_array($members) ? count($members) : 0,
            'penduduk_count' => is_array($pendudukIds) ? count($pendudukIds) : 0,
            'completed_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Final processing metrics for the completed run — the OCR outcome
     * (status + confidence), end-to-end wall time, extracted text size and
     * the imported family sizes.
     *
     * @return array<string, mixed>
     */
    private function buildMetrics(OcrJob $job): array
    {
        $data = is_array($job->extracted_data) ? $job->extracted_data : [];
        $members = $data['members'] ?? [];
        $pendudukIds = $data['penduduk_ids'] ?? [];

        $durationMs = $job->started_at !== null && $job->finished_at !== null
            ? max(0, (int) $job->finished_at->diffInMilliseconds($job->started_at))
            : null;

        return [
            'ocr_status' => $job->status?->value,
            'confidence' => $job->confidence !== null ? (float) $job->confidence : null,
            'duration_ms' => $durationMs,
            'word_count' => $job->raw_text !== null ? str_word_count(trim($job->raw_text)) : 0,
            'member_count' => is_array($members) ? count($members) : 0,
            'imported_penduduk_count' => is_array($pendudukIds) ? count($pendudukIds) : 0,
        ];
    }

    /**
     * Persist the completion onto the OCR job and append the audit entry.
     * Runs inside the finalization transaction.
     *
     * Kept `protected` (not `final`) so a test subclass can make this step
     * throw, proving the completion rolls back (job stays un-completed, no
     * audit entry) when the job update fails.
     *
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $metrics
     */
    protected function markJobCompleted(OcrJob $job, array $summary, array $metrics, ?User $operator): void
    {
        $data = is_array($job->extracted_data) ? $job->extracted_data : [];

        $data['ocr_completed_at'] = $data['ocr_completed_at'] ?? now()->toDateTimeString();
        $data['completion_summary'] = $summary;
        $data['processing_metrics'] = $metrics;

        $job->extracted_data = $data;
        $job->status = OcrJobStatus::COMPLETED;
        $job->finished_at = $job->finished_at ?? now();
        $job->save();

        AuditLog::create([
            'loggable_type' => $job->getMorphClass(),
            'loggable_id' => $job->id,
            'actor_type' => $operator?->getMorphClass(),
            'actor_id' => $operator?->id,
            'event' => 'ocr.completed',
            'new_values' => [
                'kk_number' => $summary['kk_number'] ?? null,
                'kartu_keluarga_id' => $job->kk_id,
                'penduduk_count' => $summary['penduduk_count'] ?? 0,
                'confidence' => $metrics['confidence'] ?? null,
            ],
        ]);
    }

    /**
     * Remove the OCR pipeline's transient processing artifacts: the
     * preprocessed intermediates on the private `ocr_temp` disk
     * (ImagePreprocessor::DISK — the only transient artifacts the pipeline
     * manages; the uploaded source document on `kk_uploads` is the persistent
     * archive and is never touched).
     *
     * Best-effort: a cleanup failure is logged as a warning and must never
     * break or roll back an already-persisted completion.
     */
    private function cleanupTempArtifacts(): void
    {
        try {
            $disk = $this->filesystem->disk(ImagePreprocessor::DISK);

            foreach ($disk->allFiles() as $file) {
                $disk->delete($file);
            }

            foreach ($disk->allDirectories() as $directory) {
                $disk->deleteDirectory($directory);
            }
        } catch (Throwable $e) {
            try {
                Log::warning('OCR finalize cleanup failed', [
                    'pipeline_stage' => 'finalize',
                    'outcome' => 'cleanup_failed',
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // Logging must never break the completion flow.
            }
        }
    }

    private function log(string $outcome, OcrJob $job, float $startedAt, ?Throwable $error = null): void
    {
        try {
            Log::info('OCR finalize '.$outcome, [
                'pipeline_stage' => 'finalize',
                'outcome' => $outcome,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'job_id' => $job->id,
                'kk_number' => $job->extracted_data['completion_summary']['kk_number'] ?? null,
                'error' => $error?->getMessage(),
            ]);
        } catch (Throwable) {
            // Logging must never break the completion flow.
        }
    }
}
