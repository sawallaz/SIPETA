<?php

namespace App\Services;

use App\Enums\BloodType;
use App\Enums\KkAnggotaStatus;
use App\Enums\OcrOutcome;
use App\Enums\ResidentStatus;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\KkAnggota;
use App\Models\Occupation;
use App\Models\OcrJob;
use App\Models\Penduduk;
use App\Models\Religion;
use App\Models\Rt;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Persist Penduduk records from an approved OCR review (Phase 5.8).
 *
 * The KartuKeluarga already exists (created by Phase 5.7), and the approved
 * review data — the Phase 5.6 corrected dataset — was snapshotted onto the OCR
 * job's `extracted_data` when 5.7 marked it SAVED. This service consumes that
 * approved snapshot and creates one `Penduduk` row (+ one ACTIVE `KkAnggota`
 * membership) per member, all linked to the KK.
 *
 * This is the operator-triggered write that completes ADR-009: OCR is an
 * assistant; the Service layer persists the approved family only after the
 * operator has reviewed and saved it (Phase 5.7 created the KK record).
 *
 * Guarantees:
 * - **Existing validation** — the approved snapshot is re-run through
 *   {@see OcrReviewService::validate()} (the same schema-grounded gate the
 *   review page uses) before any write; a tampered or incomplete dataset is
 *   rejected up front (`invalid`, zero writes).
 * - **Duplicate NIK detection** (FR-OCR-05) — `penduduk.nik` is unique; the
 *   approved NIK set is checked for intra-list repeats and against existing
 *   `penduduk` rows, and the insert is wrapped so a concurrent insert that
 *   wins the race also resolves to a `duplicate` result, never a partial
 *   write.
 * - **Domain mapping** — enumerated fields map onto the existing domain:
 *   gender / marital_status / family_relation / blood_type / resident_status
 *   are values from their enums (blood_type defaults to TIDAK_DIKETAHUI and
 *   resident_status to ACTIVE); religion / education / occupation resolve to
 *   the evolving lookup masters (`religions` / `educations` /
 *   `occupations`), created on the fly when an approved label is absent; the
 *   reviewed `rt` resolves to an existing `Rt` by number.
 * - **Transactional write** — every Penduduk + KkAnggota insert and the
 *   OCR-job update happen in one DB transaction; a failed job update rolls
 *   the whole family back (no orphan residents).
 * - **OCR job updated on success** — the approved snapshot in
 *   `extracted_data` is augmented with a `penduduk_imported_at` timestamp and
 *   the created `penduduk_ids`, recording the completed family import for
 *   audit. The `status` / `outcome` / `kk_id` columns are left untouched (they
 *   reflect the Phase 5.7 KK save).
 * - **Idempotence guard** — re-running the import on a job that already has a
 *   Penduduk-import marker returns `already_imported` and writes nothing.
 *
 * This class is intentionally not `final` and {@see markJobImported()} is
 * `protected` so rollback behaviour can be verified by a test subclass that
 * makes the job-save step fail.
 */
class PendudukImportService
{
    public function __construct(
        private readonly OcrParsingService $parsing,
        private readonly OcrReviewService $review,
    ) {}

    /**
     * Import the approved OCR review members into Penduduk under the KK the
     * job was saved against (Phase 5.7).
     *
     * @param  User|null  $operator  the operator running the import (recorded
     *                               on the OCR job's pending import marker)
     * @return PendudukImportResult the outcome — never throws for business
     *                              decisions (duplicate, invalid, already
     *                              imported), but rethrows a fatal error from
     *                              the write step
     *
     * @throws InvalidArgumentException when the job has no saved KK to import
     *                                  into (not yet Phase 5.7-imported)
     */
    public function import(OcrJob $job, ?User $operator = null): PendudukImportResult
    {
        $startedAt = microtime(true);

        $kk = $this->assertSavedKk($job);

        if ($this->alreadyImported($job)) {
            return PendudukImportResult::alreadyImported($kk);
        }

        $data = $job->extracted_data ?? [];
        $review = $this->review->validate(
            $this->parsing->parse((string) $job->raw_text, (float) $job->confidence),
            $data,
        );

        if (! $review->isValid()) {
            return PendudukImportResult::invalid($review->errors(), $kk);
        }

        $members = $data['members'] ?? [];

        if ($members === [] || ! is_array($members)) {
            return PendudukImportResult::invalid(['members' => 'Tidak ada anggota keluarga untuk diimpor'], $kk);
        }

        $duplicateNik = $this->duplicateNik($members);

        if ($duplicateNik !== null) {
            return PendudukImportResult::duplicate($kk, $duplicateNik);
        }

        $rt = $this->resolveRt((string) ($data['rt'] ?? ''));

        if ($rt === null) {
            return PendudukImportResult::invalid(
                ['rt' => sprintf('RT tidak ditemukan di wilayah (\'%s\') — daftarkan RT terlebih dahulu.', (string) ($data['rt'] ?? ''))],
                $kk,
            );
        }

        try {
            $pendudukIds = DB::transaction(function () use ($job, $kk, $members, $rt, $operator): array {
                $ids = [];

                // Wilayah adalah milik Kartu Keluarga (ADR-004): simpan RT yang
                // di-resolve ke KK dulu, lalu setiap Penduduk mewarisi rt_id-nya
                // melalui hook Penduduk::booted(). Kolom penduduk.rt_id tetap
                // NOT NULL, jadi KK wajib punya RT sebelum Penduduk dibuat.
                if ($kk->rt_id !== $rt->id) {
                    $kk->rt_id = $rt->id;
                    $kk->save();
                }

                foreach ($members as $member) {
                    $penduduk = Penduduk::create($this->pendudukAttributes($kk, $member, $rt->id));
                    $ids[] = $penduduk->id;

                    // Preserve the parsed family relation in the membership-
                    // history table alongside the penduduk row (ADR-008).
                    KkAnggota::create([
                        'kk_id' => $kk->id,
                        'penduduk_id' => $penduduk->id,
                        'family_relation' => (string) ($member['family_relation'] ?? ''),
                        'status' => KkAnggotaStatus::AKTIF->value,
                        'effective_date' => now()->toDateString(),
                    ]);
                }

                $this->markJobImported($job, $ids, $operator);

                return $ids;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the NIK insert race — the transaction rolled back and
            // nothing was persisted. Report against the colliding NIK.
            return PendudukImportResult::duplicate($kk, (string) ($this->firstExistingNik($members) ?? ''));
        } catch (Throwable $e) {
            $this->log('failure', $job, $kk->kk_number, $startedAt, $e);
            throw $e;
        }

        $this->log('saved', $job, $kk->kk_number, $startedAt);

        return PendudukImportResult::saved($kk, count($pendudukIds));
    }

    /**
     * Reject jobs whose KartuKeluarga has not been Phase 5.7-imported: the
     * Penduduk import needs the KK already created, the job marked SAVED and
     * its raw text available to re-run the review gate against the approved
     * snapshot.
     *
     * @throws InvalidArgumentException
     */
    private function assertSavedKk(OcrJob $job): KartuKeluarga
    {
        if ($job->kk_id === null || $job->outcome !== OcrOutcome::SAVED->value || ! filled($job->raw_text)) {
            throw new InvalidArgumentException(
                sprintf('OCR job %d has no imported KartuKeluarga to attach Penduduk to (expected outcome %s + kk_id, got %s).', $job->id, OcrOutcome::SAVED->value, $job->outcome ?? 'null')
            );
        }

        $kk = KartuKeluarga::find($job->kk_id);

        if ($kk === null) {
            throw new InvalidArgumentException(sprintf('OCR job %d points at kartu_keluarga id %d which no longer exists.', $job->id, $job->kk_id));
        }

        return $kk;
    }

    /**
     * The Penduduk import is idempotent: once the approved snapshot carries
     * the `penduduk_imported_at` marker the family has already been created.
     */
    private function alreadyImported(OcrJob $job): bool
    {
        $data = $job->extracted_data;

        return is_array($data) && isset($data['penduduk_imported_at']);
    }

    /**
     * Check the approved NIK list for duplicates among themselves and against
     * already-created `penduduk` rows. The first offending NIK is returned.
     *
     * @param  array<int, array<string, mixed>>  $members
     */
    private function duplicateNik(array $members): ?string
    {
        $niks = [];

        foreach ($members as $member) {
            $nik = (string) ($member['nik'] ?? '');

            if ($nik === '') {
                continue;
            }

            if (in_array($nik, $niks, true)) {
                return $nik;
            }

            $niks[] = $nik;
        }

        return Penduduk::query()->whereIn('nik', $niks)->value('nik');
    }

    /**
     * The NIK that actually exists in `penduduk` after an insert race, so a
     * UniqueConstraintViolationException can report which NIK collided.
     *
     * @param  array<int, array<string, mixed>>  $members
     */
    private function firstExistingNik(array $members): ?string
    {
        foreach ($members as $member) {
            $nik = (string) ($member['nik'] ?? '');

            if ($nik !== '' && Penduduk::where('nik', $nik)->exists()) {
                return $nik;
            }
        }

        return null;
    }

    /**
     * Map an approved member row onto the existing Penduduk column set.
     *
     * @param  array<string, mixed>  $member
     * @return array<string, mixed>
     */
    private function pendudukAttributes(KartuKeluarga $kk, array $member, int $rtId): array
    {
        return [
            'kk_id' => $kk->id,
            'nik' => (string) ($member['nik'] ?? ''),
            'full_name' => (string) ($member['nama'] ?? ''),
            'gender' => (string) ($member['gender'] ?? ''),
            'birth_place' => (string) ($member['birth_place'] ?? ''),
            'birth_date' => $this->normalizeBirthDate((string) ($member['birth_date'] ?? '')),
            'religion_id' => $this->resolveLookupId(Religion::class, (string) ($member['religion'] ?? '')),
            'education_id' => $this->resolveLookupId(Education::class, (string) ($member['education'] ?? '')),
            'occupation_id' => $this->resolveLookupId(Occupation::class, (string) ($member['occupation'] ?? '')),
            'marital_status' => (string) ($member['marital_status'] ?? ''),
            'family_relation' => (string) ($member['family_relation'] ?? ''),
            'blood_type' => BloodType::TIDAK_DIKETAHUI->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'rt_id' => $rtId,
        ];
    }

    /**
     * Resolve a lookup label (religion / education / occupation) to a row in
     * the corresponding master table, creating the master row (title-cased)
     * when the label is not yet present — the masters are a data-driven,
     * evolving taxonomy. Case-insensitive equality.
     *
     * @param  class-string<Model>  $model
     */
    private function resolveLookupId(string $model, string $label): int
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $label) ?? $label);

        $existing = $model::query()
            ->whereRaw('UPPER(name) = ?', [mb_strtoupper($normalized)])
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) $model::create(['name' => $this->titleCase($normalized)])->id;
    }

    /**
     * The OCR parser emits Y-m-d (e.g. 2016-01-28); an operator correction may
     * carry d/m/Y / d-m-Y. Normalize to the DATE column's Y-m-d.
     */
    private function normalizeBirthDate(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('~^(\d{1,2})[-/](\d{1,2})[-/](\d{2,4})$~', $value, $m) === 1) {
            $year = (int) $m[3];

            if ($year < 100) {
                $year += 2000;
            }

            return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
        }

        // Unreachable after the review gate validates the date.
        return $value;
    }

    private function titleCase(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Resolve the reviewed `rt` (e.g. "001") to an existing Rt by number.
     * Numbers are normalized (leading zeros) to match the seeded `rts` rows
     * ("01".."09"). When the same number exists under several area units the
     * first (by id) is chosen — the KK card carries no area unit, so the
     * area-unit is deliberately kept out of scope.
     */
    private function resolveRt(string $value): ?Rt
    {
        $value = trim($value);

        if ($value === '' || preg_match('/^\d{1,3}$/', $value) !== 1) {
            return null;
        }

        $number = str_pad((string) ((int) $value), 2, '0', STR_PAD_LEFT);

        return Rt::query()->where('number', $number)->orderBy('id')->first();
    }

    /**
     * Persist the Penduduk-import marker onto the OCR job's approved snapshot:
     * record the created families' ids and when / by whom the family was
     * imported. Runs inside the import transaction.
     *
     * Kept `protected` (not `final`) so a test subclass can make this step
     * throw, proving the Penduduk / KkAnggota inserts roll back when the job
     * update fails.
     *
     * @param  array<int, int>  $pendudukIds
     */
    protected function markJobImported(OcrJob $job, array $pendudukIds, ?User $operator): void
    {
        $data = is_array($job->extracted_data) ? $job->extracted_data : [];

        $data['penduduk_imported_at'] = now()->toDateTimeString();
        $data['penduduk_ids'] = $pendudukIds;
        $data['penduduk_operator_id'] = $operator?->id;

        $job->extracted_data = $data;
        $job->save();
    }

    private function log(string $outcome, OcrJob $job, ?string $kkNumber, float $startedAt, ?Throwable $error = null): void
    {
        try {
            Log::info('OCR Penduduk import '.$outcome, [
                'pipeline_stage' => 'import_penduduk',
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
