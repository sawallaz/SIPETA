<?php

namespace App\Services;

use App\Exceptions\DatabaseDumperException;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * SQLite database dumper for ZIP backups (Phase 6.2 / Tauri).
 *
 * Produces a self-contained SQL dump of all application tables and data using
 * pure PDO, with no external CLI dependencies (no mysqldump or sqlite3 binary required).
 */
class SqliteDatabaseDumper implements DatabaseDumper
{
    public function dump(): string
    {
        try {
            /** @var PDO $pdo */
            $pdo = DB::connection('sqlite')->getPdo();

            $sql = [];
            $sql[] = '-- SIPETA SQLite Database Dump';
            $sql[] = '-- Generated: '.date('Y-m-d H:i:s');
            $sql[] = 'PRAGMA foreign_keys = OFF;';
            $sql[] = 'BEGIN TRANSACTION;';
            $sql[] = '';

            // Get all user tables in creation order
            $tablesStmt = $pdo->query(
                "SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY name;"
            );
            $tables = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($tables as $table) {
                $tableName = $table['name'];
                $createSql = $table['sql'];

                $sql[] = "-- Table structure for `{$tableName}`";
                $sql[] = "DROP TABLE IF EXISTS \"{$tableName}\";";
                $sql[] = $createSql.';';
                $sql[] = '';

                // Get table rows
                $rowsStmt = $pdo->query("SELECT * FROM \"{$tableName}\"");
                $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

                if (! empty($rows)) {
                    $sql[] = "-- Dumping data for `{$tableName}`";
                    $columns = array_keys($rows[0]);
                    $escapedCols = implode(', ', array_map(fn ($c) => "\"{$c}\"", $columns));

                    foreach ($rows as $row) {
                        $values = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $values[] = 'NULL';
                            } elseif (is_numeric($val) && ! is_string($val)) {
                                $values[] = (string) $val;
                            } else {
                                $values[] = $pdo->quote((string) $val);
                            }
                        }
                        $valuesStr = implode(', ', $values);
                        $sql[] = "INSERT INTO \"{$tableName}\" ({$escapedCols}) VALUES ({$valuesStr});";
                    }
                    $sql[] = '';
                }
            }

            $sql[] = 'COMMIT;';
            $sql[] = 'PRAGMA foreign_keys = ON;';

            return implode("\n", $sql);
        } catch (Throwable $e) {
            throw new DatabaseDumperException('Gagal membuat dump database SQLite: '.$e->getMessage());
        }
    }
}
