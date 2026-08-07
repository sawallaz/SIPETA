<?php

namespace App\Services;

/**
 * Outcome of a backup attempt (Phase 6.2 — ZIP backup).
 *
 * In-memory only, never persisted. `success` means the archive was written to
 * the db_backups disk and logged; `duplicate` means an archive with the same
 * filename already exists and nothing was written (FR-BR-03).
 */
final readonly class BackupResult
{
    private function __construct(
        public string $status,
        public string $filename,
        public ?int $size = null,
    ) {}

    public static function success(string $filename, int $size): self
    {
        return new self('success', $filename, $size);
    }

    public static function duplicate(string $filename): self
    {
        return new self('duplicate', $filename);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isDuplicate(): bool
    {
        return $this->status === 'duplicate';
    }
}
