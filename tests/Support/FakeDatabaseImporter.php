<?php

namespace Tests\Support;

use App\Services\DatabaseImporter;

/**
 * Deterministic DatabaseImporter for the restore suite.
 * Records every applied dump so tests can assert what was restored.
 */
final class FakeDatabaseImporter implements DatabaseImporter
{
    /** Any SQL dump applied by the importer. */
    public array $applied = [];

    public function __construct(private readonly bool $shouldFail = false) {}

    public static function failing(): self
    {
        return new self(true);
    }

    public function apply(string $sql): void
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('simulated database import failure');
        }

        $this->applied[] = $sql;
    }
}
