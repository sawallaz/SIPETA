<?php

namespace App\Services;

use App\Exceptions\DatabaseImporterException;

/**
 * Database import contract (Phase 6.3 — restore from backup).
 *
 * Implementations apply a SQL dump to the application database. The Restore
 * service feeds the `database.sql` entry of a backup archive into an importer
 * so the operator can roll the database back to a backed-up state.
 *
 * @see SqliteDatabaseImporter the production implementation
 */
interface DatabaseImporter
{
    /**
     * Apply a SQL dump to the database.
     *
     * @param  string  $sql  the SQL dump text
     *
     * @throws DatabaseImporterException when the dump cannot be applied
     */
    public function apply(string $sql): void;
}
