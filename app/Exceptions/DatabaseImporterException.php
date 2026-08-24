<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a database dump cannot be applied during a restore.
 */
class DatabaseImporterException extends RuntimeException {}
