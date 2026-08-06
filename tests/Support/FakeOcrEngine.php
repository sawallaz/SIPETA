<?php

namespace Tests\Support;

use App\Exceptions\OcrEngineException;
use App\Services\OcrEngine;
use App\Services\OcrResult;

/**
 * Test double for the OCR engine (Phase 5.4).
 *
 * Pipeline tests bind this into the container so the real Tesseract binary is
 * never invoked. Behavior is configured per test via the result / exception
 * properties; the last image path handed to the engine is recorded so tests
 * can assert the engine ran on the preprocessed image.
 */
class FakeOcrEngine implements OcrEngine
{
    public OcrResult $result;

    public ?OcrEngineException $exception = null;

    public string $lastImagePath = '';

    public function __construct(?OcrResult $result = null)
    {
        $this->result = $result ?? new OcrResult('', 0.0, 0, 0.0);
    }

    public function run(string $imagePath): OcrResult
    {
        $this->lastImagePath = $imagePath;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->result;
    }
}
