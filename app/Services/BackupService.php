<?php

namespace App\Services;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Exceptions\BackupException;
use App\Models\BackupLog;
use App\Models\KkPhoto;
use App\Models\PendudukDocument;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Phase 6.2 — ZIP backup (FR-BR-01 / FR-BR-02 / FR-BR-03, FR-AUD-01).
 *
 * Produces a temporary `backup_YYYY-MM-DD_HHMMSS.zip` containing:
 *   - `database.sql`  — a SQL dump of the database (FR-BR-01), via the injected
 *                       DatabaseDumper (tests use a fake so no real mysqldump runs);
 *   - `settings.json` — the singleton settings row (FR-BR-01);
 *   - `kk/*`          — every archived KK photo copied from its storage disk (FR-BR-01).
 *
 * The temporary archive is uploaded to Google Drive and is always removed in
 * the same request. Every attempt is recorded in `backup_logs` (FR-AUD-01):
 * SUCCESS with Drive metadata, or FAILED with a message.
 */
class BackupService
{
    public function __construct(private DatabaseDumper $dumper) {}

    /**
     * The archive filename: `backup_YYYY-MM-DD_HHMMSS.zip` (FR-BR-02).
     */
    public function filename(?Carbon $now = null): string
    {
        return 'backup_'.($now ?? now())->format('Y-m-d_His').'.zip';
    }

    public function createToDrive(User $operator, GoogleDriveClient $drive): BackupResult
    {
        $lock = Cache::lock('sipeta:google-drive-backup', 900);
        if (! $lock->get()) {
            throw new BackupException('Backup sedang diproses. Silakan tunggu hingga selesai.');
        }

        $startedAt = now();
        $filename = $this->filename($startedAt);

        $existing = BackupLog::query()
            ->where('filename', $filename)
            ->where('backup_status', BackupStatus::SUCCESS)
            ->whereNotNull('drive_file_id')
            ->first();

        if ($existing !== null) {
            $lock->release();

            return BackupResult::success(
                $existing->filename,
                $existing->backup_size,
                $existing->checksum,
                $existing->drive_file_id,
                $existing->drive_folder_id,
            );
        }

        $log = null;
        $tmp = null;

        try {
            Log::info('Backup started.', ['filename' => $filename, 'operator_id' => $operator->id]);
            $log = $this->record($operator, $filename, BackupStatus::PENDING, 0, $startedAt, null);
            $archive = $this->buildArchive();
            $tmp = $archive['path'];
            $size = filesize($tmp);
            if ($size === false) {
                throw new BackupException('Ukuran arsip backup tidak dapat dibaca.');
            }

            $folder = $drive->ensureBackupFolder();
            $log->update([
                'backup_status' => BackupStatus::RUNNING,
                'backup_size' => (int) $size,
                'checksum' => $archive['checksum'],
                'drive_folder_id' => $folder['id'],
                'finished_at' => null,
            ]);

            $metadata = $drive->upload($tmp, $folder['id'], $filename, $archive['checksum']);
            if (
                blank($metadata['id'] ?? null)
                ||
                ($metadata['name'] ?? null) !== $filename
                || ($metadata['parents'][0] ?? null) !== $folder['id']
                || ($metadata['appProperties']['sipeta_checksum'] ?? null) !== $archive['checksum']
                || (isset($metadata['size']) && (int) $metadata['size'] !== (int) $size)
            ) {
                throw new BackupException('Metadata backup Google Drive tidak sesuai setelah upload.');
            }

            $log->update([
                'backup_status' => BackupStatus::SUCCESS,
                'drive_file_id' => (string) $metadata['id'],
                'finished_at' => now(),
            ]);
            Log::info('Backup completed.', [
                'filename' => $filename,
                'drive_file_id' => $metadata['id'],
                'operator_id' => $operator->id,
            ]);

            return BackupResult::success($filename, (int) $size, $archive['checksum'], (string) $metadata['id'], $folder['id']);
        } catch (\Throwable $e) {
            if ($log !== null) {
                $log->update([
                    'backup_status' => BackupStatus::FAILED,
                    'finished_at' => now(),
                    'message' => $e->getMessage(),
                ]);
            }
            Log::error('Backup failed.', ['filename' => $filename, 'operator_id' => $operator->id]);

            throw new BackupException($e->getMessage(), (int) $e->getCode(), $e);
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
            $lock->release();
        }
    }

    /**
     * Build the ZIP archive to a temp file and return its absolute path.
     *
     * @return array{path: string, checksum: string, file_count: int, total_size: int}
     *
     * @throws BackupException when the archive cannot be assembled
     */
    private function buildArchive(): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sipeta_backup_');
        if ($tmp === false) {
            throw new BackupException('File sementara backup tidak dapat dibuat.');
        }

        $zip = new ZipArchive;
        $opened = false;

        try {
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new BackupException('Tidak dapat membuat arsip ZIP.');
            }
            $opened = true;

            $settings = Setting::query()->first();
            $settingsData = $settings?->only([
                'id',
                'kelurahan_name',
                'kecamatan_name',
                'kabupaten_name',
                'province_name',
                'logo_path',
            ]) ?? [];
            $payloads = [
                'database.sql' => $this->dumper->dump(),
                'settings.json' => (string) json_encode($settingsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ];

            foreach (KkPhoto::query()->get() as $photo) {
                $disk = Storage::disk($photo->storage_disk ?? 'local');
                if ($photo->storage_path === null || $photo->stored_filename === null || ! $disk->exists($photo->storage_path)) {
                    continue;
                }
                $bytes = $disk->get($photo->storage_path);
                $payloads['kk/'.$photo->stored_filename] = $bytes;
                $payloads['storage/'.trim($photo->storage_disk, '/').'/'.ltrim($photo->storage_path, '/')] = $bytes;
            }

            foreach (PendudukDocument::query()->get() as $document) {
                if ($document->storage_path === null) {
                    continue;
                }
                $disk = Storage::disk($document->storage_disk ?? 'local');
                if (! $disk->exists($document->storage_path)) {
                    continue;
                }
                $payloads['storage/'.trim($document->storage_disk, '/').'/'.ltrim($document->storage_path, '/')] = $disk->get($document->storage_path);
            }

            if ($settings?->logo_path !== null && Storage::disk('local')->exists($settings->logo_path)) {
                $payloads['storage/local/'.ltrim($settings->logo_path, '/')] = Storage::disk('local')->get($settings->logo_path);
            }

            $context = hash_init('sha256');
            $totalSize = 0;
            foreach ($payloads as $name => $bytes) {
                $zip->addFromString($name, $bytes);
                hash_update($context, $name."\0".$bytes);
                $totalSize += strlen($bytes);
            }

            $checksum = hash_final($context);
            $zip->addFromString('manifest.json', (string) json_encode([
                'application_identifier' => 'SIPETA',
                'app_version' => (string) config('app.version', 'unknown'),
                'backup_version' => '1.0',
                'created_at' => now()->toIso8601String(),
                'database_driver' => (string) config('database.default'),
                'file_count' => count($payloads),
                'total_size' => $totalSize,
                'checksum' => $checksum,
                'backup_type' => BackupType::MANUAL->value,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if (! $zip->close()) {
                $opened = false;

                throw new BackupException('Arsip backup tidak dapat ditutup dengan benar (kemungkinan korup).');
            }
            $opened = false;

            return [
                'path' => $tmp,
                'checksum' => $checksum,
                'file_count' => count($payloads),
                'total_size' => $totalSize,
            ];
        } catch (\Throwable $e) {
            if ($opened) {
                $zip->close();
            }
            @unlink($tmp);

            if ($e instanceof BackupException) {
                throw $e;
            }

            throw new BackupException($e->getMessage(), (int) $e->getCode(), $e);
        }
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
        ?string $message,
        ?string $checksum = null,
    ): BackupLog {
        return BackupLog::create([
            'filename' => $filename,
            'backup_type' => BackupType::MANUAL,
            'backup_status' => $status,
            'backup_size' => $size,
            'checksum' => $checksum,
            'operator_id' => $operator?->id,
            'started_at' => $startedAt,
            'finished_at' => now(),
            'message' => $message,
        ]);
    }
}
