<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Singleton kelurahan identity + backup path (Phase 6.5).
 *
 * The `settings` table is a single-row singleton (id = 1). ADR-020 and FR-SET-02
 * require exactly one row, created on first access and never deleted; this is
 * enforced here via `firstOrCreate(['id' => 1])`, never in the Filament page.
 *
 * FR-SET-01 the editable fields are: kelurahan, kecamatan, kabupaten and
 * province names, logo path and backup path. The logo is stored on the `local`
 * disk under a `logos/` prefix — only the relative path is persisted in
 * `logo_path` (no extra filesystem disk is added, and `config/filesystems.php`
 * is not touched). `backup_path` is operator configuration recorded for future
 * phases only: the Phase 6.2 `BackupService` continues to use its own existing
 * implementation and is intentionally not modified here.
 */
class SettingsService
{
    public const SINGLETON_ID = 1;

    /** `local` disk directory prefix under which the logo is stored. */
    public const LOGO_DIR = 'logos';

    /**
     * The singleton settings row, created on first access (FR-SET-02).
     */
    public function get(): Setting
    {
        return Setting::firstOrCreate(
            ['id' => self::SINGLETON_ID],
            $this->defaults(),
        );
    }

    /**
     * Persist the settings row (identity, logo path, backup path).
     *
     * Only fillable fields are written; the row is never deleted. Logo and
     * backup-path handling is deliberately minimal: the page passes the
     * relative logo path it already stored, and `backup_path` is recorded for
     * future phases only.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): Setting
    {
        $setting = $this->get();

        $setting->fill([
            'kelurahan_name' => $data['kelurahan_name'] ?? null,
            'kecamatan_name' => $data['kecamatan_name'] ?? null,
            'kabupaten_name' => $data['kabupaten_name'] ?? null,
            'province_name' => $data['province_name'] ?? null,
            'logo_path' => $data['logo_path'] ?? null,
            'backup_path' => $data['backup_path'] ?? null,
        ])->save();

        return $setting;
    }

    /**
     * Default profile used when the singleton is created on first access.
     *
     * Mirrors the seeded defaults in `SettingsSeeder`.
     *
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'kelurahan_name' => env('SETTINGS_KELURAHAN_NAME', 'Kelurahan Tanete'),
            'kecamatan_name' => env('SETTINGS_KECAMATAN_NAME', 'Tanete'),
            'kabupaten_name' => env('SETTINGS_KABUPATEN_NAME', 'Barru'),
            'province_name' => env('SETTINGS_PROVINCE_NAME', 'Sulawesi Selatan'),
            'logo_path' => env('SETTINGS_LOGO_PATH'),
            'backup_path' => env('SETTINGS_BACKUP_PATH', storage_path('app/backups')),
        ];
    }
}
