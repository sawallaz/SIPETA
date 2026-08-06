<?php

namespace App\Services;

use App\Exceptions\OcrEngineException;

/**
 * OCR engine contract (Phase 5.4, .ai/ocr.md §4.3 / §12).
 *
 * Implementations run text extraction over a preprocessed image and return
 * raw text plus an aggregated confidence. The engine performs extraction
 * only — no parsing, no database mapping.
 *
 * @see TesseractOcrEngine the production implementation
 */
interface OcrEngine
{
    /**
     * Run text extraction over an image file.
     *
     * @param  string  $imagePath  absolute path of the image to read
     *
     * @throws OcrEngineException when the engine binary fails, times out, or
     *                            produces unusable output
     */
    public function run(string $imagePath): OcrResult;
}
