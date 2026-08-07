<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a database dump cannot be applied during a restore (mysql client
 * not found, connection failure, non-zero exit). The calling restore service
 * aborts the restore before any further state is touched.
 */
class DatabaseImporterException extends RuntimeException {}
