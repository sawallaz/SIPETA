<?php

namespace App\Services;

use App\Exceptions\DatabaseImporterException;
use Symfony\Component\Process\Process;

/**
 * Production database import via the `mysql` client (Phase 6.3 — restore).
 *
 * Pipes the SQL dump to `mysql` over stdin using the MySQL connection settings
 * from config/database.php (never hard-coded credentials). A dump produced by
 * `mysqldump` (see MysqldumpDatabaseDumper) round-trips through this client.
 */
class MysqlClientDatabaseImporter implements DatabaseImporter
{
    /** Seconds before a mysql import call is killed. */
    private const TIMEOUT = 180;

    public function apply(string $sql): void
    {
        $conn = config('database.connections.mysql');

        $command = array_values(array_filter([
            'mysql',
            '--host='.($conn['host'] ?? '127.0.0.1'),
            '--port='.($conn['port'] ?? 3306),
            '--user='.($conn['username'] ?? ''),
            ! empty($conn['password']) ? '--password='.$conn['password'] : null,
            $conn['database'],
        ], fn ($segment) => $segment !== null));

        $process = new Process($command);
        $process->setTimeout(self::TIMEOUT);
        $process->setInput($sql);
        $process->run();

        if (! $process->isSuccessful()) {
            $detail = trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'mysql exited unsuccessfully';
            throw new DatabaseImporterException($detail);
        }
    }
}
