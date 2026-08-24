<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the configured database dump cannot be produced.
 */
class DatabaseDumperException extends RuntimeException {}
