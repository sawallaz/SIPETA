<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OCR Engine Configuration (.ai/ocr.md §6)
    |--------------------------------------------------------------------------
    |
    | Engine settings for the Tesseract invocation performed by
    | App\Services\TesseractOcrEngine (Phase 5.4).
    |
    | The upload size cap (5 MB, .ai/ocr.md §4.1) is owned by
    | App\Services\KkDocumentUploadService (Phase 5.1) and the resolution
    | bounds (min 800×600, max 4000×4000) by App\Services\ImagePreprocessor
    | (Phase 5.3) — they are not duplicated here.
    |
    */

    // Path to the Tesseract binary. Defaults to the binary on PATH; the
    // Windows desktop packaging (Phase 7) sets TESSERACT_PATH explicitly
    // (.ai/ocr.md §6).
    'tesseract_path' => env('TESSERACT_PATH', 'tesseract'),

    // Engine invocation (.ai/ocr.md §4.3): Indonesian language pack, single
    // uniform text block page-segmentation mode, TSV output for word-level
    // confidence.
    'language' => 'ind',

    'psm' => '6',

    // .ai/ocr.md §6: below this mean word confidence a job is recorded as
    // LOW_CONFIDENCE instead of SUCCESS.
    'confidence_threshold' => 70,

    // .ai/ocr.md §6 / §4.9: per-image engine timeout. A timeout fails the
    // job ("Tesseract timeout (>10s) → Cancel, show timeout").
    'timeout_seconds' => 10,

    // .ai/ocr.md §7: retention for transient pipeline files. The 24-hour GC
    // cycle is a later sub-phase.
    'temp_retention_hours' => 24,

];
