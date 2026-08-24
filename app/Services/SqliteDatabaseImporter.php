<?php

namespace App\Services;

use App\Exceptions\DatabaseImporterException;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * SQLite database importer for restore operations (Phase 6.3 / Tauri).
 *
 * Applies a SQL dump to the application's SQLite database using pure PDO
 * within an isolated transaction and foreign key safety guards.
 */
class SqliteDatabaseImporter implements DatabaseImporter
{
    public function apply(string $sql): void
    {
        if (trim($sql) === '') {
            throw new DatabaseImporterException('File dump SQL database kosong.');
        }

        try {
            /** @var PDO $pdo */
            $pdo = DB::connection('sqlite')->getPdo();

            // If we are already inside a transaction (e.g. RefreshDatabase in tests),
            // remove transaction control statements from the dump to prevent nested transaction errors.
            $cleanSql = $sql;
            if ($pdo->inTransaction()) {
                $cleanSql = preg_replace('/^\s*(BEGIN TRANSACTION|COMMIT|ROLLBACK)\s*;/mi', '', $cleanSql) ?? $cleanSql;
            }

            $pdo->exec('PRAGMA foreign_keys = OFF;');
            $pdo->exec($cleanSql);
            $pdo->exec('PRAGMA foreign_keys = ON;');
        } catch (Throwable $e) {
            throw new DatabaseImporterException('Gagal memulihkan database SQLite: '.$e->getMessage());
        }
    }
}
