<?php

namespace App\Services;

use App\Enums\OcrOutcome;
use App\Models\KartuKeluarga;
use App\Models\OcrJob;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Persist a validated OCR review result into the Kartu Keluarga domain
 * (Phase 5.7).
 *
 * This is the operator-triggered "SIMPAN" write (ADR-009 — OCR is an
 * assistant; the Service layer writes only after the operator explicitly
 * approves). Consumes the approved review data from Phase 5.6 and maps it to
 * a {@see KartuKeluarga} record. Penduduk membership (the `members` rows) is
 * NOT created here — that is a later sub-phase.
 *
 * Guarantees:
 * - **Existing domain model + validation** — the supplied corrections are
 *   re-run through {@see OcrReviewService::validate()} (the same schema-
 *   grounded gate the review page uses) before anything is written, so an
 *   un-validated or tampered payload is rejected up front.
 * - **Duplicate KK detection** (FR-OCR-05, KK-number rule) — `kk_number` is a
 *   unique column; existence is pre-checked and the insert is wrapped so a
 *   concurrent insert that wins the race also resolves to a `duplicate`
 *   result, never a partial write.
 * - **Transactional write** — the KK insert and the OCR-job save happen in
 *   one DB transaction; if the job update fails, the KK creation is rolled
 *   back (no orphan KK row).
 * - **OCR job updated on success** — the job is marked saved: `outcome`
 *   SAVED, `kk_id` linked, `reviewed_at`, `operator_id`, and the approved
 *   data snapshot persisted to `extracted_data` for audit.
 *
 * This class is intentionally not `final` and {@see markJobSaved()} is
 * `protected` so rollback behaviour can be verified by a test subclass that
 * makes the job-save step fail.
 */
class OcrImportService
{
    public function __construct(
        private readonly OcrParsingService $parsing,
        private readonly OcrReviewService $review,
    ) {}

    /**
     * Import an approved OCR review result into the Kartu Keluarga domain.
     *
     * @param  array<string, mixed>  $correctedData  operator-approved review
     *                                               data (kk_number, address,
     *                                               rt, rw, lingkungan,
     *                                               members[]) — the same
     *                                               shape {@see OcrReviewService
     *                                               ::validate()} understands.
     * @param  User|null  $operator  the admin approving the import (recorded
     *                               on the OCR job)
     * @return OcrImportResult the outcome — never throws for business
     *                         decisions (invalid, duplicate KK, already saved),
     *                         but rethrows a fatal error from the write step
     *
     * @throws InvalidArgumentException when the job is not in a reviewable,
     *                                  unsaved state
     */
    public function import(OcrJob $job, array $correctedData, ?User $operator = null): OcrImportResult
    {
        $startedAt = microtime(true);

        $this->assertUnsavedReviewable($job);

        if ($job->kk_id !== null || $job->outcome !== null) {
            return OcrImportResult::alreadySaved((int) ($job->kk_id ?? 0));
        }

        $review = $this->review->validate(
            $this->parsing->parse((string) $job->raw_text, (float) $job->confidence),
            $correctedData,
        );

        if (! $review->isValid()) {
            return OcrImportResult::invalid($review->errors(), $this->kkNumber($review->correctedData()));
        }

        $data = $review->correctedData();
        $kkNumber = (string) $data['kk_number'];

        if ($this->kkExists($kkNumber)) {
            return OcrImportResult::duplicate($kkNumber);
        }

        try {
            $kartuKeluarga = DB::transaction(function () use ($job, $data, $kkNumber, $operator): KartuKeluarga {
                $created = KartuKeluarga::create([
                    'kk_number' => $kkNumber,
                    'address' => (string) $data['address'],
                ]);

                $this->markJobSaved($job, $created, $operator, $data);

                return $created;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the insert race for this kk_number — the transaction rolled
            // back and nothing was persisted. Report as a duplicate.
            return OcrImportResult::duplicate($kkNumber);
        } catch (Throwable $e) {
            $this->log('failure', $job, $kkNumber, $startedAt, $e);
            throw $e;
        }

        $this->log('saved', $job, $kkNumber, $startedAt);

        return OcrImportResult::saved((int) $kartuKeluarga->id, $kkNumber);
    }

    /**
     * Reject jobs that are not importable: they must be in a terminal
     * extracted state with raw text (the same gate the review page uses).
     *
     * @throws InvalidArgumentException
     */
    private function assertUnsavedReviewable(OcrJob $job): void
    {
        if (! OcrReviewService::isReviewable($job)) {
            throw new InvalidArgumentException(
                sprintf('OCR job %d cannot be imported: status must be SUCCESS or LOW_CONFIDENCE with raw text, got %s.', $job->id, $job->status?->value ?? 'unknown')
            );
        }
    }

    /**
     * Persist the approved data onto the OCR job: link the KK, mark the
     * outcome SAVED, record who approved and when, and snapshot the approved
     * dataset for audit. Runs inside the import transaction.
     *
     * Kept `protected` (not `final`) so a test subclass can make this step
     * throw, proving the KK insert rolls back when the job update fails.
     *
     * @param  array<string, mixed>  $data
     */
    protected function markJobSaved(OcrJob $job, KartuKeluarga $kartuKeluarga, ?User $operator, array $data): void
    {
        $job->kk_id = $kartuKeluarga->id;
        $job->outcome = OcrOutcome::SAVED;
        $job->reviewed_at = now();
        $job->operator_id = $operator?->id;
        $job->extracted_data = $data;
        $job->save();
    }

    private function kkExists(string $kkNumber): bool
    {
        return KartuKeluarga::where('kk_number', $kkNumber)->exists();
    }

    /**
     * @param  array<string, mixed>  $correctedData
     */
    private function kkNumber(array $correctedData): ?string
    {
        return isset($correctedData['kk_number']) && $correctedData['kk_number'] !== ''
            ? (string) $correctedData['kk_number']
            : null;
    }

    private function log(string $outcome, OcrJob $job, ?string $kkNumber, float $startedAt, ?Throwable $error = null): void
    {
        try {
            Log::info('OCR import '.$outcome, [
                'pipeline_stage' => 'import',
                'outcome' => $outcome,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'job_id' => $job->id,
                'kk_number' => $kkNumber,
                'error' => $error?->getMessage(),
            ]);
        } catch (Throwable) {
            // Logging must never break the import flow.
        }
    }
}
