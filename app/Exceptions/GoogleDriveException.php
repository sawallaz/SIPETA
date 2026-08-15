<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleDriveException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
