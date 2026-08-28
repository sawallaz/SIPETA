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
     * @param  string|null  $tableRawText  raw text extracted specifically from table bounding box
     * @param  string|null  $tsv  raw TSV output from Tesseract
     * @param  array<int, array{text: string, conf: float, left: int, top: int, width: int, height: int, cx: float, cy: float}>|null  $tokens
     * @param  string|null  $tableTsv  raw TSV output from specialized table pass
     * @param  array<int, array{text: string, conf: float, left: int, top: int, width: int, height: int, cx: float, cy: float}>|null  $tableTokens
     */
    public function __construct(
        public string $rawText,
        public float $confidence,
        public int $wordCount,
        public float $durationMs,
        public ?string $tableRawText = null,
        public ?string $tsv = null,
        public ?array $tokens = null,
        public ?string $tableTsv = null,
        public ?array $tableTokens = null,
    ) {}
}
