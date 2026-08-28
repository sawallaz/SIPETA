<?php

namespace App\Services;

/**
 * Structured result of OCR text parsing (Phase 5.5).
 *
 * The mapping of raw OCR text (produced by the Phase 5.4 engine) into the
 * project-defined fields (FR-OCR-02). In-memory DTO only — never persisted;
 * the review sub-phase consumes this object to pre-populate the operator
 * form.
 *
 * Only project-defined fields are extracted, nothing is invented:
 *
 *   KK level  : nomor KK, alamat, RT, RW, lingkungan
 *   member    : nama, NIK, jenis kelamin, tempat lahir, tanggal lahir,
 *               agama, pendidikan, pekerjaan, status perkawinan, status
 *               hubungan keluarga
 *
 * Parsing never throws for missing, malformed, or duplicated input: missing
 * values stay null, duplicated labels keep the first occurrence, malformed
 * rows are skipped, and every degradation is recorded in warnings /
 * validationErrors for the operator-facing review form.
 */
final readonly class ParsedOcrResult
{
    /**
     * @param  array<int, ParsedResident>  $members  parsed KK members in
     *                                               table order, each with a
     *                                               valid 16-digit NIK
     * @param  array<int, string>  $warnings  non-blocking observations:
     *                                        duplicates dropped, malformed
     *                                        rows skipped, unreadable labels
     * @param  array<int, string>  $validationErrors  required-field / format
     *                                                problems surfaced to the
     *                                                review form, never
     *                                                thrown (.ai/ocr.md §4.7)
     */
    public function __construct(
        public float $confidence,
        public bool $lowConfidence,
        public ?string $kkNumber,
        public ?string $address,
        public ?string $rt,
        public ?string $rw,
        public ?string $lingkungan,
        public array $members,
        public array $warnings,
        public array $validationErrors,
        public float $durationMs,
        public ?string $postalCode = null,
        public ?string $namaKepalaKeluarga = null,
        public ?string $kelurahan = null,
        public ?string $kecamatan = null,
        public ?string $kabupaten = null,
        public ?string $provinsi = null,
    ) {}

    /**
     * True when nothing usable was extracted (no KK number and no members) —
     * the ".ai/ocr.md §4.6 low-yield" signal the review flow uses to fall
     * back to manual input.
     */
    public function isEmpty(): bool
    {
        return $this->kkNumber === null && $this->members === [];
    }

    public function isValid(): bool
    {
        return empty($this->validationErrors);
    }

    public function memberCount(): int
    {
        return count($this->members);
    }
}
