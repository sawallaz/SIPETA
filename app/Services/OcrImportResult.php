<?php

namespace App\Services;

/**
 * Result of a KartuKeluarga import attempt (Phase 5.7).
 *
 * Produced by {@see OcrImportService::import()}: the outcome of persisting a
 * validated OCR review result into the Kartu Keluarga domain. Purely an
 * in-memory value object returned to the caller (a controller/Filament action
 * in a later sub-phase) — it is never persisted and carries no side effects.
 *
 * Statuses:
 * - `saved`         — the KK record was created and the OCR job marked saved
 *                     (outcome SAVED, kk_id linked, reviewed_at, operator).
 * - `duplicate`     — the KK number already exists in `kartu_keluarga`
 *                     (FR-OCR-05 KK-number duplicate rule); nothing written.
 * - `invalid`       — the supplied data failed the validation gate (existing
 *                     `OcrReviewService` rules); nothing written.
 * - `already_saved` — the job was already linked to a KK (kk_id set or
 *                     outcome SAVED); re-import refused, nothing written.
 */
final readonly class OcrImportResult
{
    public const STATUS_SAVED = 'saved';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_ALREADY_SAVED = 'already_saved';

    /**
     * @param  array<string, string>  $errors  validation-error messages, only
     *                                         populated on an `invalid` result
     */
    public function __construct(
        public string $status,
        public ?int $kartuKeluargaId = null,
        public ?string $kkNumber = null,
        public array $errors = [],
    ) {}

    public static function saved(int $kartuKeluargaId, string $kkNumber): self
    {
        return new self(self::STATUS_SAVED, $kartuKeluargaId, $kkNumber);
    }

    public static function duplicate(string $kkNumber): self
    {
        return new self(self::STATUS_DUPLICATE, null, $kkNumber);
    }

    /**
     * @param  array<string, string>  $errors
     */
    public static function invalid(array $errors, ?string $kkNumber = null): self
    {
        return new self(self::STATUS_INVALID, null, $kkNumber, $errors);
    }

    public static function alreadySaved(int $kartuKeluargaId): self
    {
        return new self(self::STATUS_ALREADY_SAVED, $kartuKeluargaId);
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

    public function isAlreadySaved(): bool
    {
        return $this->status === self::STATUS_ALREADY_SAVED;
    }
}
