<?php

namespace App\Services;

/**
 * Outcome of a restore attempt (Phase 6.3 — restore from backup).
 *
 * In-memory only, never persisted. `restored` means the backup archive was
 * validated (FR-BR-04) and applied; the operator must then restart the
 * application (FR-BR-06). `confirmation_required` means the caller did not
 * pass the explicit confirmation (FR-BR-05) and nothing was applied.
 */
final readonly class RestoreResult
{
    private function __construct(
        public string $status,
        public string $filename,
        public bool $restartRequired = false,
    ) {}

    public static function restored(string $filename): self
    {
        return new self('restored', $filename, true);
    }

    public static function confirmationRequired(string $filename): self
    {
        return new self('confirmation_required', $filename);
    }

    public function isRestored(): bool
    {
        return $this->status === 'restored';
    }

    public function isConfirmationRequired(): bool
    {
        return $this->status === 'confirmation_required';
    }
}
