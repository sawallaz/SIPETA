<?php

namespace Tests\Support;

use App\Services\DatabaseDumper;

/**
 * Deterministic DatabaseDumper for the backup suite — no real mysqldump runs.
 */
final class FakeDatabaseDumper implements DatabaseDumper
{
    public function __construct(
        private readonly string $sql = 'CREATE TABLE example (id INT);',
        private readonly bool $shouldFail = false,
    ) {}

    public static function failing(): self
    {
        return new self('', true);
    }

    public function dump(): string
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('simulated mysqldump failure');
        }

        return $this->sql;
    }
}
