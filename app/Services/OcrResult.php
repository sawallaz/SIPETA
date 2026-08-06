<?php

namespace App\Services;

/**
 * Result of the OCR engine stage (Phase 5.4).
 *
 * In-memory DTO — the extractable fields are persisted onto the ocr_jobs row
 * (raw_text, confidence, status) by the pipeline; the DTO itself is never
 * stored. Word-level confidence is aggregated to a single mean by the engine
 * implementation.
 */
final readonly class OcrResult
{
    /**
     * @param  string  $rawText  extracted text, lines reconstructed from
     *                           word-level TSV output in reading order
     * @param  float  $confidence  mean word confidence (0–100)
     * @param  int  $wordCount  number of words the engine reported
     * @param  float  $durationMs  engine wall time in milliseconds
     */
    public function __construct(
        public string $rawText,
        public float $confidence,
        public int $wordCount,
        public float $durationMs,
    ) {}
}
