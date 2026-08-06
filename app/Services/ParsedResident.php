<?php

namespace App\Services;

/**
 * One parsed KK table member (Phase 5.5).
 *
 * In-memory DTO produced by OcrParsingService::parse() — never persisted
 * (ADR-009: OCR is an assistant; nothing is written until the operator
 * saves).
 *
 * Free-text fields (nama, birthPlace) carry the original OCR text; the
 * enumerated fields carry canonical values matching the project enum /
 * vocabulary sets so a later mapping phase can resolve them:
 *
 *   gender        LAKI_LAKI | PEREMPUAN
 *   maritalStatus BELUM_KAWIN | KAWIN | CERAI_HIDUP | CERAI_MATI
 *   familyRelation KEPALA_KELUARGA | ISTRI | ... | LAINNYA
 *   religion      ISLAM | KRISTEN | KATOLIK | HINDU | BUDDHA | KONGHUCU
 *   education     recognized education label
 *   occupation    recognized occupation label
 *
 * Fields OCR could not recognize are null — the review UI treats null as
 * "not extracted" and asks the operator to fill them.
 *
 * confidence is the engine's aggregate mean confidence carried onto the
 * member: the engine (Phase 5.4) exposes only an aggregated value, so
 * per-field word-level confidence (.ai/ocr.md §4.4) is not yet available —
 * see docs/PHASE5.md §5.5.3.
 */
final readonly class ParsedResident
{
    public function __construct(
        public ?string $nama,
        public ?string $nik,
        public ?string $gender,
        public ?string $birthPlace,
        public ?string $birthDate,
        public ?string $religion,
        public ?string $education,
        public ?string $occupation,
        public ?string $maritalStatus,
        public ?string $familyRelation,
        public float $confidence,
        public bool $lowConfidence,
    ) {}
}
