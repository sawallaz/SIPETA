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
 * Applies the archive produced by BackupService (FR-BR-01) to bring the
 * application back to a backed-up state. The service:
 *   - refuses to run without explicit confirmation (FR-BR-05);
 *   - validates the archive integrity BEFORE applying anything (FR-BR-04);
 *   - applies the SQL dump (database.sql) through the injected DatabaseImporter,
 *     then re-applies the settings singleton and the KK photos;
 *   - always advises the operator to restart the application (FR-BR-06).
 *
 * Service-layer only (same pattern as the Phase 5.7/5.8 imports and 6.2 backup);
 * the operator-facing restore control ships with the later UI sub-phase.
 */
class RestoreService
{
    /** Private disk holding the ZIP archives (matches BackupService). */
    public const DISK = 'db_backups';

    /** Disk KK photos are written back to (backup stores them under `kk/*`). */
    public const PHOTO_DISK = 'kk_uploads';

    /** Settings columns restored from the archive's settings.json. */
    private const SETTING_FIELDS = [
        'kelurahan_name',
        'kecamatan_name',
        'kabupaten_name',
        'province_name',
        'logo_path',
        'backup_path',
    ];

    public function __construct(private DatabaseImporter $importer) {}

    /**
     * Restore the application state from a backup archive.
     *
     * @param  string  $filename  the archive filename on the db_backups disk
     * @param  ?User  $operator  the operator performing the restore (diagnostics)
     * @param  bool  $confirmed  FR-BR-05 explicit confirmation gate
     *
     * @throws RestoreException when the archive is missing, invalid, or cannot be applied
     */
    public function restore(string $filename, ?User $operator = null, bool $confirmed = false): RestoreResult
    {
        // FR-BR-05: an explicit confirmation must precede any restore, and the
        // caller must reference an existing archive.
        if (! $confirmed) {
            return RestoreResult::confirmationRequired($filename);
        }

        if (! Storage::disk(self::DISK)->exists($filename)) {
            throw new RestoreException(sprintf('Arsip backup %s tidak ditemukan.', $filename));
        }

        // FR-BR-04: validate the archive integrity before applying anything.
        [$sql, $settingsJson, $photoFiles] = $this->extractValidated($filename, Storage::disk(self::DISK)->path($filename));

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

            Log::info('Arsip backup diterapkan.', [
                'filename' => $filename,
                'operator_id' => $operator?->id,
                'photo_count' => count($photoFiles),
            ]);
        } catch (\Throwable $e) {
            throw new RestoreException($e->getMessage(), (int) $e->getCode(), $e);
        }

        // FR-BR-06: after a restore the operator must restart the application.
        return RestoreResult::restored($filename);
    }

    /**
     * Open the archive and read every applied entry, rejecting it before any
     * change is made when integrity fails (FR-BR-04).
     *
     * @return array{0: string, 1: string, 2: array<string, string>}
     *                                                               [SQL dump, settings.json, kk/* file entries keyed by entry name]
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

            $photoFiles = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if (str_starts_with($name, 'kk/')) {
                    $bytes = $zip->getFromName($name);

                    if ($bytes !== false) {
                        $photoFiles[$name] = $bytes;
                    }
                }
            }

            return [$sql, $settingsJson, $photoFiles];
        } finally {
            $zip->close();
        }
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
