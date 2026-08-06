<?php

namespace App\Services;

use App\Models\KartuKeluarga;

/**
 * Result of a Penduduk import attempt (Phase 5.8).
 *
 * Produced by {@see PendudukImportService::import()}: the outcome of persisting
 * the approved OCR review members (Phase 5.7's `extracted_data` snapshot) as
 * `Penduduk` rows under a KartuKeluarga that Phase 5.7 already created. Purely
 * an in-memory value object returned to the caller — it is never persisted and
 * carries no side effects.
 *
 * Statuses:
 * - `saved`             — every approved member became a `Penduduk` row (+ one
 *                         ACTIVE `KkAnggota` membership each) under the KK,
 *                         and the OCR job was marked as penduduk-imported.
 * - `duplicate`         — at least one member NIK already exists in `penduduk`
 *                         (or repeats within the approved list); nothing
 *                         written (FR-OCR-05 NIK duplicate rule).
 * - `invalid`           — the approved data failed the existing validation
 *                         gate or no RT could be resolved; nothing written.
 * - `already_imported`  — the job already has a Penduduk import marker;
 *                         re-import refused, nothing written.
 */
final readonly class PendudukImportResult
{
    public const STATUS_SAVED = 'saved';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_ALREADY_IMPORTED = 'already_imported';

    /**
     * @param  array<string, string>  $errors  validation-error messages, only
     *                                         populated on an `invalid` result
     */
    public function __construct(
        public string $status,
        public ?int $kartuKeluargaId = null,
        public ?string $kkNumber = null,
        public int $importedCount = 0,
        public ?string $duplicateNik = null,
        public array $errors = [],
    ) {}

    public static function saved(KartuKeluarga $kk, int $importedCount): self
    {
        return new self(self::STATUS_SAVED, $kk->id, (string) $kk->kk_number, $importedCount);
    }

    public static function duplicate(KartuKeluarga $kk, string $nik): self
    {
        return new self(self::STATUS_DUPLICATE, $kk->id, (string) $kk->kk_number, 0, $nik);
    }

    /**
     * @param  array<string, string>  $errors
     */
    public static function invalid(array $errors, KartuKeluarga $kk): self
    {
        return new self(self::STATUS_INVALID, $kk->id, (string) $kk->kk_number, 0, null, $errors);
    }

    public static function alreadyImported(KartuKeluarga $kk): self
    {
        return new self(self::STATUS_ALREADY_IMPORTED, $kk->id, (string) $kk->kk_number);
    }

    public function isSaved(): bool
    {
        return $this->status === self::STATUS_SAVED;
    }

    public function isDuplicate(): bool
    {
        return $this->status === self::STATUS_DUPLICATE;
    }

    public function isInvalid(): bool
    {
        return $this->status === self::STATUS_INVALID;
    }

    public function isAlreadyImported(): bool
    {
        return $this->status === self::STATUS_ALREADY_IMPORTED;
    }
}
