<?php

namespace App\Services;

/**
 * Outcome of a Google Drive backup attempt (Phase 6.2).
 *
 * In-memory only, never persisted. The archive itself is temporary; this DTO
 * carries only the metadata needed by the Google Drive history/UI.
 */
final readonly class BackupResult
{
    private function __construct(
        public string $status,
        public string $filename,
        public ?int $size = null,
        public ?string $checksum = null,
        public ?string $driveFileId = null,
        public ?string $driveFolderId = null,
    ) {}

    public static function success(
        string $filename,
        int $size,
        ?string $checksum = null,
        ?string $driveFileId = null,
        ?string $driveFolderId = null,
    ): self {
        return new self('success', $filename, $size, $checksum, $driveFileId, $driveFolderId);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }
}
