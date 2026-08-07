<?php

namespace App\Services;

/**
 * Outcome of a backup-archive integrity check (Phase 6.6 — FR-MED-04).
 *
 * In-memory only, never persisted. `ok` means the archive opened as a valid
 * ZIP and contains the required `database.sql` + `settings.json` entries,
 * both readable; `corrupt` means at least one integrity requirement failed,
 * with the human-readable issues listed. One result per archive inspected.
 */
final readonly class BackupIntegrityResult
{
    private function __construct(
        public string $status,
        public string $filename,
        public array $issues,
    ) {}

    public static function ok(string $filename): self
    {
        return new self('ok', $filename, []);
    }

    /**
     * @param  array<int, string>  $issues  human-readable integrity failures
     */
    public static function corrupt(string $filename, array $issues): self
    {
        return new self('corrupt', $filename, $issues);
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    public function isCorrupt(): bool
    {
        return $this->status === 'corrupt';
    }
}
