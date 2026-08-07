<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a backup operation cannot complete (ZIP creation, photo/settings
 * read, or persistence failure). The service appends a backup_logs row with
 * backup_status FAILED before this exception surfaces.
 */
class BackupException extends RuntimeException {}
