<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a restore operation cannot complete — the backup archive is
 * missing, fails integrity validation (FR-BR-04), or applying its contents
 * (database dump / settings / photos) fails.
 */
class RestoreException extends RuntimeException {}
