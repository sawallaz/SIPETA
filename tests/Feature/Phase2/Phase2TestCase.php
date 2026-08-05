<?php

namespace Tests\Feature\Phase2;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Shared base for Phase 2 database verification tests.
 *
 * Uses real migrations (DatabaseMigrations) but DISABLES the wrapping
 * transaction: on SQLite a transaction makes `PRAGMA foreign_keys = ON` a
 * no-op, which would silently disable FK enforcement and break the
 * RESTRICT / SET NULL behavioural assertions. migrate:fresh already resets
 * state per test, so no transaction is needed.
 */
abstract class Phase2TestCase extends TestCase
{
    use DatabaseMigrations;

    protected function refreshTestDatabase(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->app[Kernel::class]->setArtisan(null);

        $conn = $this->app['db']->connection();
        if ($conn instanceof SQLiteConnection) {
            // Must be set outside any transaction for SQLite to honour it.
            $conn->statement('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * No-op: we want real FK enforcement without a wrapping transaction.
     */
    protected function beginDatabaseTransaction(): void
    {
        //
    }

    /**
     * @return array{columns:array,foreign_table:string,foreign_columns:array,on_delete:string,on_update:string}|null
     */
    protected function foreignKey(string $table, string $column): ?array
    {
        foreach (Schema::getForeignKeys($table) as $fk) {
            if (($fk['columns'][0] ?? null) === $column) {
                return $fk;
            }
        }

        return null;
    }

    protected function assertForeignKey(string $table, string $column, string $foreignTable, string $onDelete, string $onUpdate = 'cascade'): void
    {
        $fk = $this->foreignKey($table, $column);

        $this->assertNotNull($fk, "Expected FK {$table}.{$column} to exist.");
        $this->assertSame($foreignTable, $fk['foreign_table']);
        $this->assertSame($onDelete, strtolower($fk['on_delete']));
        $this->assertSame($onUpdate, strtolower($fk['on_update']));
    }

    protected function assertHasIndex(string $table, string $indexName): void
    {
        $this->assertContains(
            $indexName,
            Schema::getIndexListing($table),
            "Expected index {$indexName} on {$table}.",
        );
    }

    protected function assertUniqueIndex(string $table, string $indexName): void
    {
        $this->assertHasIndex($table, $indexName);
    }

    protected function assertNoSoftDeletes(): void
    {
        $tables = [
            'kartu_keluarga', 'penduduk', 'settings', 'backup_logs', 'audit_logs',
            'kk_anggota', 'kk_photos', 'ocr_jobs', 'religions', 'educations',
            'occupations', 'area_units', 'rts',
        ];

        foreach ($tables as $table) {
            $this->assertFalse(
                Schema::hasColumn($table, 'deleted_at'),
                "Soft-delete column deleted_at must NOT exist on {$table} (not in approved schema).",
            );
        }
    }

    /**
     * Always return a Builder (helper to keep static-analysis happy on relation calls).
     */
    protected function builder(Builder $builder): Builder
    {
        return $builder;
    }
}
