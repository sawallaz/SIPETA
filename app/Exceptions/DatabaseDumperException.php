<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the configured database dump cannot be produced (mysqldump not
 * found, connection failure, non-zero exit). The calling backup service marks
 * the backup FAILED in backup_logs before this surfaces to the caller.
 */
class DatabaseDumperException extends RuntimeException {}
