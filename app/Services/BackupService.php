<?php

namespace App\Services;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Exceptions\BackupException;
use App\Models\BackupLog;
use App\Models\KkPhoto;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Phase 6.2 — ZIP backup (FR-BR-01 / FR-BR-02 / FR-BR-03, FR-AUD-01).
 *
 * Produces a single `backup_YYYY-MM-DD_HHMMSS.zip` on the private `db_backups`
 * disk containing:
 *   - `database.sql`  — a SQL dump of the database (FR-BR-01), via the injected
 *                       DatabaseDumper (tests use a fake so no real mysqldump runs);
 *   - `settings.json` — the singleton settings row (FR-BR-01);
 *   - `kk/*`          — every archived KK photo copied from its storage disk (FR-BR-01).
 *
 * The filename embeds the date + time (FR-BR-02) and an existing archive is
 * NEVER overwritten (FR-BR-03). Every attempt is recorded in `backup_logs`
 * (FR-AUD-01): SUCCESS with the archive size, or FAILED with a message.
 */
class BackupService
{
    /** Private disk holding the ZIP archives. */
    public const DISK = 'db_backups';

    public function __construct(private DatabaseDumper $dumper) {}

    /**
     * The archive filename: `backup_YYYY-MM-DD_HHMMSS.zip` (FR-BR-02).
     */
    public function filename(?Carbon $now = null): string
    {
        return 'backup_'.($now ?? now())->format('Y-m-d_His').'.zip';
    }

    /**
     * Create a full backup. Records SUCCESS / FAILED in backup_logs.
     */
    public function create(?User $operator = null): BackupResult
    {
        $startedAt = now();
        $filename = $this->filename($startedAt);

        // FR-BR-03: an existing archive is never overwritten.
        if (Storage::disk(self::DISK)->exists($filename)) {
            return BackupResult::duplicate($filename);
        }

        try {
            $tmp = $this->buildArchive();
            $stream = fopen($tmp, 'rb');
            Storage::disk(self::DISK)->writeStream($filename, $stream);
            fclose($stream);
            @unlink($tmp);

            $size = Storage::disk(self::DISK)->size($filename);
            $this->record($operator, $filename, BackupStatus::SUCCESS, $size, $startedAt, null);

            return BackupResult::success($filename, $size);
        } catch (\Throwable $e) {
            $this->record($operator, $filename, BackupStatus::FAILED, 0, $startedAt, $e->getMessage());

            throw new BackupException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Build the ZIP archive to a temp file and return its absolute path.
     *
     * @throws BackupException when the archive cannot be assembled
     */
    private function buildArchive(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sipeta_backup_');

        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupException('Tidak dapat membuat arsip ZIP.');
        }

        $zip->addFromString('database.sql', $this->dumper->dump());

        $zip->addFromString('settings.json', json_encode(
            Setting::query()->first()?->toArray() ?? [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));

        foreach (KkPhoto::query()->get() as $photo) {
            $disk = Storage::disk($photo->storage_disk ?? 'local');
            if ($photo->storage_path === null || $photo->stored_filename === null || ! $disk->exists($photo->storage_path)) {
                continue;
            }
            // Photos are bounded (≤5 MB uploads), so read into memory for the archive.
            $zip->addFromString('kk/'.$photo->stored_filename, $disk->get($photo->storage_path));
        }

        $zip->close();

        return $tmp;
    }

    /**
     * Append the backup_logs entry (FR-AUD-01).
     */
    private function record(
        ?User $operator,
        string $filename,
        BackupStatus $status,
        int $size,
        Carbon $startedAt,
        ?string $message
    ): void {
        BackupLog::create([
            'filename' => $filename,
            'backup_type' => BackupType::MANUAL,
            'backup_status' => $status,
            'backup_size' => $size,
            'operator_id' => $operator?->id,
            'started_at' => $startedAt,
            'finished_at' => now(),
            'message' => $message,
        ]);
    }
}
