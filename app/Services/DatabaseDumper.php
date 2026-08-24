<?php

namespace App\Services;

use App\Exceptions\DatabaseDumperException;

/**
 * Database dump contract (Phase 6.2 — ZIP backup, FR-BR-01).
 *
 * Implementations produce a SQL dump of the application database that the
 * backup service embeds in the archive as `database.sql`.
 *
 * @see SqliteDatabaseDumper the production implementation
 */
interface DatabaseDumper
{
    /**
     * Produce a SQL dump of the database.
     *
     * @return string the SQL dump text
     *
     * @throws DatabaseDumperException when the dump cannot be produced
     */
    public function dump(): string;
}
