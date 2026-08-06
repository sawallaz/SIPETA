<?php

namespace App\Services;

/**
 * Result of review-field validation (Phase 5.6).
 *
 * Produced by {@see OcrReviewService::validate()}: the operator-side validation
 * gate "before approval" (ADR-009 — OCR is an assistant, never auto-saves).
 * Carries the merged effective values (parsed baseline + operator corrections)
 * plus the field-keyed validation errors. Purely in-memory — the actual import
 * is deferred to a later phase, so nothing here ever touches the database.
 */
final readonly class OcrReviewResult
{
    /**
     * @param  array<string, string>  $errors  field path => operator-facing
     *                                         message, e.g. "members.0.nik =>
     *                                         NIK anggota ke-1 wajib diisi"
     * @param  array<string, mixed>  $correctedData  effective review data:
     *                                               parsed baseline merged
     *                                               with operator corrections
     */
    public function __construct(
        public bool $isValid,
        public array $errors,
        public array $correctedData,
        public float $durationMs,
    ) {}

    /** True when every required/format rule passed (ready for import review). */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function correctedData(): array
    {
        return $this->correctedData;
    }
}
