<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an OCR job cannot continue processing.
 *
 * The job is marked FAILED (with error_message and finished_at) before this
 * exception surfaces to the caller.
 */
class OcrProcessingException extends RuntimeException {}
