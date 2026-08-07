<?php

namespace App\Services;

use App\Exceptions\DatabaseDumperException;
use Symfony\Component\Process\Process;

/**
 * Production database dump via the `mysqldump` client (Phase 6.2 — ZIP backup).
 *
 * Runs `mysqldump --single-transaction` using the MySQL connection settings
 * from config/database.php (never hard-coded credentials). `--single-transaction`
 * keeps the dump non-blocking for the single-operator database.
 */
class MysqldumpDatabaseDumper implements DatabaseDumper
{
    /** Seconds before a mysqldump call is killed. */
    private const TIMEOUT = 120;

    public function dump(): string
    {
        $conn = config('database.connections.mysql');

        $command = array_values(array_filter([
            'mysqldump',
            '--single-transaction',
            '--host='.($conn['host'] ?? '127.0.0.1'),
            '--port='.($conn['port'] ?? 3306),
            '--user='.($conn['username'] ?? ''),
            ! empty($conn['password']) ? '--password='.$conn['password'] : null,
            $conn['database'],
        ], fn ($segment) => $segment !== null));

        $process = new Process($command);
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            $detail = trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'mysqldump exited unsuccessfully';
            throw new DatabaseDumperException($detail);
        }

        return $process->getOutput();
    }
}
