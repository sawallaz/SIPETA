<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Phase 6.6 — Backup integrity check (FR-MED-04 / F-MED-04).
 *
 * Inspects every backup archive stored on the private `db_backups` disk and
 * reports whether each is usable for a restore. A backup is healthy when it
 * opens as a valid ZIP AND exposes both required entries — `database.sql` and
 * `settings.json` — readable. This mirrors the FR-BR-04 validation performed
 * by RestoreService before applying, but it is a read-only, run-at-launch
 * health check: it never opens, extracts, or mutates an archive, and it never
 * touches the database. It exists to surface a corrupted or incomplete backup
 * BEFORE the operator relies on it (NFR-REL-01 — data integrity is the
 * highest priority).
 *
 * The check is exposed to the operator as the `backup:integrity-check` artisan
 * command, the natural "on launch" entry point for a desktop-delivered app.
 */
class BackupIntegrityService
{
    /** Private disk holding the ZIP archives (matches BackupService/RestoreService). */
    public const DISK = 'db_backups';

    /** Required, readable entries a healthy backup must contain. */
    private const REQUIRED_ENTRIES = ['database.sql', 'settings.json'];

    /**
     * Inspect every backup archive on the disk.
     *
     * Non-`.zip` files are ignored (they are not backups). The inspection is
     * read-only — it validates ZIP openability, entry presence, and entry
     * readability, but never opens into the database and never changes disk.
     *
     * @return array<int, BackupIntegrityResult> one result per archive, in
     *                                           disk enumeration order
     */
    public function checkAll(): array
    {
        $disk = Storage::disk(self::DISK);

        return collect($disk->files())
            ->filter(fn (string $file) => str_ends_with($file, '.zip'))
            ->map(fn (string $file) => $this->check($file))
            ->values()
            ->all();
    }

    /**
     * Inspect a single backup archive.
     *
     * @throws \RuntimeException when the archive does not exist on the disk
     */
    public function check(string $filename): BackupIntegrityResult
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($filename)) {
            throw new \RuntimeException(sprintf('Arsip backup %s tidak ditemukan.', $filename));
        }

        $path = $disk->path($filename);
        $issues = [];

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return BackupIntegrityResult::corrupt($filename, [
                'Arsip tidak dapat dibuka sebagai ZIP yang valid atau sudah korup.',
            ]);
        }

        try {
            foreach (self::REQUIRED_ENTRIES as $required) {
                if ($zip->locateName($required) === false) {
                    $issues[] = sprintf('Entri wajib %s tidak ditemukan.', $required);
                } elseif ($zip->getFromName($required) === false) {
                    $issues[] = sprintf('Entri %s tidak dapat dibaca (kemungkinan korup).', $required);
                }
            }
        } finally {
            $zip->close();
        }

        if ($issues !== []) {
            return BackupIntegrityResult::corrupt($filename, $issues);
        }

        return BackupIntegrityResult::ok($filename);
    }
}
