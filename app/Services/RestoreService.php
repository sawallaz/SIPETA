<?php

namespace App\Services;

use App\Exceptions\RestoreException;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Phase 6.3 — Restore from ZIP backup (FR-BR-04 / FR-BR-05 / FR-BR-06).
 *
 * Applies a temporary download of a Google Drive archive (FR-BR-01) to bring
 * the application back to a backed-up state. The service:
 *   - refuses to run without explicit confirmation (FR-BR-05);
 *   - validates the archive integrity BEFORE applying anything (FR-BR-04);
 *   - applies the SQL dump (database.sql) through the injected DatabaseImporter,
 *     then re-applies the settings singleton and the KK photos;
 *   - always advises the operator to restart the application (FR-BR-06).
 *
 * Service-layer only; the downloaded archive is always removed after use.
 */
class RestoreService
{
    /** Disk KK photos are written back to (backup stores them under `kk/*`). */
    public const PHOTO_DISK = 'kk_uploads';

    private const MAX_ARCHIVE_ENTRIES = 10000;

    private const MAX_ENTRY_BYTES = 262144000;

    private const MAX_TOTAL_BYTES = 2147483648;

    /** Settings columns restored from the archive's settings.json. */
    private const SETTING_FIELDS = [
        'kelurahan_name',
        'kecamatan_name',
        'kabupaten_name',
        'province_name',
        'logo_path',
    ];

    public function __construct(
        private DatabaseImporter $importer,
        private GoogleDriveClient $drive,
    ) {}

    public function restoreFromDrive(string $fileId, ?User $operator = null, bool $confirmed = false): RestoreResult
    {
        if (! $confirmed) {
            return RestoreResult::confirmationRequired($fileId);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'sipeta_drive_restore_');
        if ($tmp === false) {
            throw new RestoreException('File sementara restore tidak dapat dibuat.');
        }

        try {
            $metadata = $this->drive->metadata($fileId);
            $expectedChecksum = $metadata['appProperties']['sipeta_checksum'] ?? null;
            if (blank($expectedChecksum)) {
                throw new RestoreException('Checksum backup Google Drive tidak tersedia.');
            }

            $this->drive->download($fileId, $tmp);

            $actualChecksum = $this->manifestChecksum($tmp) ?? hash_file('sha256', $tmp);
            if ($actualChecksum === false || ! hash_equals((string) $expectedChecksum, $actualChecksum)) {
                throw new RestoreException('Checksum backup tidak valid.');
            }

            return $this->restoreArchive('Google Drive: '.$fileId, $tmp, $operator);
        } finally {
            @unlink($tmp);
        }
    }

    private function manifestChecksum(string $path): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        try {
            $manifest = $zip->getFromName('manifest.json');
            $data = $manifest === false ? null : json_decode($manifest, true);

            return is_array($data) && filled($data['checksum'] ?? null)
                ? (string) $data['checksum']
                : null;
        } finally {
            $zip->close();
        }
    }

    private function restoreArchive(string $filename, string $path, ?User $operator): RestoreResult
    {
        Log::info('Restore started.', ['filename' => $filename, 'operator_id' => $operator?->id]);

        // FR-BR-04: validate the archive integrity before applying anything.
        [$sql, $settingsJson, $photoFiles, $storageFiles] = $this->extractValidated($filename, $path);

        try {
            // 1. The SQL dump is the authoritative state; if it fails, nothing
            //    else is touched.
            $this->importer->apply($sql);

            // 2. Re-apply the settings singleton when a row was backed up.
            $this->applySettings($settingsJson);

            // 3. Restore the archived KK photo files. kk_uploads is configured
            //    with throw=false, so a failed write returns false instead of
            //    throwing — surface it rather than reporting a false success
            //    with the DB already restored (NFR-REL-01).
            foreach ($photoFiles as $name => $bytes) {
                if (Storage::disk(self::PHOTO_DISK)->put($name, $bytes) === false) {
                    throw new RestoreException(sprintf('Foto %s gagal dipulihkan ke disk.', $name));
                }
            }

            foreach ($storageFiles as $entry) {
                if (Storage::disk($entry['disk'])->put($entry['path'], $entry['bytes']) === false) {
                    throw new RestoreException(sprintf('File %s gagal dipulihkan ke disk.', $entry['path']));
                }
            }

            Log::info('Arsip backup diterapkan.', [
                'filename' => $filename,
                'operator_id' => $operator?->id,
                'photo_count' => count($photoFiles),
            ]);
        } catch (\Throwable $e) {
            Log::error('Restore failed.', ['filename' => $filename, 'operator_id' => $operator?->id]);
            throw new RestoreException($e->getMessage(), (int) $e->getCode(), $e);
        }

        // FR-BR-06: after a restore the operator must restart the application.
        return RestoreResult::restored($filename);
    }

    /**
     * Open the archive and read every applied entry, rejecting it before any
     * change is made when integrity fails (FR-BR-04).
     *
     * @return array{0: string, 1: string, 2: array<string, string>, 3: array<string, array{disk: string, path: string, bytes: string}>}
     *
     * @throws RestoreException
     */
    private function extractValidated(string $filename, string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RestoreException(sprintf('Arsip backup %s tidak valid atau korup.', $filename));
        }

        try {
            foreach (['database.sql', 'settings.json'] as $required) {
                if ($zip->locateName($required) === false) {
                    throw new RestoreException(sprintf('Arsip backup %s tidak lengkap: %s tidak ditemukan.', $filename, $required));
                }
            }

            $sql = $zip->getFromName('database.sql');
            $settingsJson = $zip->getFromName('settings.json');

            if ($sql === false || $settingsJson === false) {
                throw new RestoreException(sprintf('Isi arsip backup %s tidak dapat dibaca.', $filename));
            }

            if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                throw new RestoreException('Arsip backup melebihi batas jumlah file yang aman.');
            }

            $manifest = $zip->getFromName('manifest.json');
            if ($manifest !== false) {
                $manifestData = json_decode($manifest, true);
                if (! is_array($manifestData)) {
                    throw new RestoreException('Manifest backup tidak valid.');
                }

                $context = hash_init('sha256');
                $payloadCount = 0;
                $payloadSize = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    if ($entry === false || $entry === 'manifest.json' || str_ends_with($entry, '/')) {
                        continue;
                    }
                    $this->assertSafeArchiveName($entry);
                    $bytes = $zip->getFromIndex($i);
                    if ($bytes === false) {
                        throw new RestoreException('Isi arsip backup tidak dapat dibaca.');
                    }
                    $payloadCount++;
                    $payloadSize += strlen($bytes);
                    hash_update($context, $entry."\0".$bytes);
                }

                if (
                    ($manifestData['application_identifier'] ?? null) !== 'SIPETA'
                    || (int) ($manifestData['file_count'] ?? -1) !== $payloadCount
                    || (int) ($manifestData['total_size'] ?? -1) !== $payloadSize
                    || ! hash_equals((string) ($manifestData['checksum'] ?? ''), hash_final($context))
                ) {
                    throw new RestoreException('Checksum backup tidak valid.');
                }
            }

            $photoFiles = [];
            $storageFiles = [];
            $totalExtracted = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || str_ends_with($name, '/')) {
                    continue;
                }
                $this->assertSafeArchiveName($name);
                $bytes = $zip->getFromName($name);
                if ($bytes === false) {
                    continue;
                }
                $this->assertEntrySize($bytes, $totalExtracted);

                if (str_starts_with($name, 'kk/')) {
                    $photoFiles[$name] = $bytes;
                } elseif (str_starts_with($name, 'storage/')) {
                    $parts = explode('/', $name, 3);
                    if (count($parts) === 3 && in_array($parts[1], ['kk_uploads', 'local'], true)) {
                        $storageFiles[$name] = ['disk' => $parts[1], 'path' => $parts[2], 'bytes' => $bytes];
                    }
                }
            }

            return [$sql, $settingsJson, $photoFiles, $storageFiles];
        } finally {
            $zip->close();
        }
    }

    private function assertSafeArchiveName(string $name): void
    {
        if (
            $name === ''
            || str_starts_with($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
            || in_array('..', explode('/', $name), true)
        ) {
            throw new RestoreException('Arsip backup memiliki path file yang tidak aman.');
        }
    }

    private function assertEntrySize(string $bytes, int &$total): void
    {
        $size = strlen($bytes);
        if ($size > self::MAX_ENTRY_BYTES || $total > self::MAX_TOTAL_BYTES - $size) {
            throw new RestoreException('Arsip backup melebihi batas ekstraksi yang aman.');
        }
        $total += $size;
    }

    /**
     * Upsert the settings singleton from the archive's settings.json.
     */
    private function applySettings(string $json): void
    {
        $data = json_decode($json, true);

        if (! is_array($data) || $data === []) {
            return;
        }

        $fields = [];

        foreach (self::SETTING_FIELDS as $key) {
            if (array_key_exists($key, $data) && ! is_null($data[$key])) {
                $fields[$key] = (string) $data[$key];
            }
        }

        if ($fields === []) {
            return;
        }

        Setting::updateOrCreate(['id' => 1], $fields);
    }
}
