<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the OCR engine cannot produce a result (binary failure,
 * timeout, unusable output).
 *
 * The job is marked FAILED (with error_message and finished_at) before this
 * exception surfaces to the caller.
 */
class OcrEngineException extends RuntimeException {}
