<?php

namespace App\Services;

/**
 * Result of the OCR image preprocessing stage (Phase 5.3).
 *
 * In-memory tracking DTO only — it is never persisted. The pipeline hands it
 * to the caller (and logs it) so the extraction sub-phase can consume the
 * preprocessed image and quality metadata. Preprocessing is stateless across
 * requests, matching the OCR pipeline design (.ai/ocr.md §8).
 */
final readonly class PreprocessResult
{
    /**
     * @param  string  $path  relative path of the preprocessed image on the ocr_temp disk
     * @param  int  $width  processed image width in pixels
     * @param  int  $height  processed image height in pixels
     * @param  float|null  $meanBrightness  sampled mean brightness (0–255), quality metric (.ai/ocr.md §4.10)
     * @param  float|null  $skewAngle  detected skew in degrees; null until automatic deskew lands (OCR engine phase)
     * @param  array<int, string>  $appliedTransforms  ordered transform names applied (exif_orientation, grayscale, resize)
     * @param  array<int, string>  $warnings  non-blocking quality flags (e.g. brightness out of range)
     * @param  float  $durationMs  preprocessing wall time in milliseconds
     */
    public function __construct(
        public string $path,
        public int $width,
        public int $height,
        public ?float $meanBrightness = null,
        public ?float $skewAngle = null,
        public array $appliedTransforms = [],
        public array $warnings = [],
        public float $durationMs = 0.0,
    ) {}
}
