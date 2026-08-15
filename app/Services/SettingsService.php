<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Singleton kelurahan identity and Google Drive connection settings.
 *
 * The `settings` table is a single-row singleton (id = 1). ADR-020 and FR-SET-02
 * require exactly one row, created on first access and never deleted; this is
 * enforced here via `firstOrCreate(['id' => 1])`, never in the Filament page.
 *
 * The logo is stored on the `local` disk under a `logos/` prefix — only the
 * relative path is persisted in `logo_path`.
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
     * Persist the settings row (identity and logo path).
     *
     * Only fillable fields are written; the row is never deleted.
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

        ])->save();

        return $setting;
    }

    /**
     * Persist the encrypted Google OAuth credential bundle and connection metadata.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function saveGoogleDriveConnection(
        array $credentials,
        ?string $email,
        ?string $folderId,
    ): Setting {
        $setting = $this->get();
        $setting->forceFill([
            'google_drive_account_email' => $email,
            'google_drive_folder_id' => $folderId,
            'google_drive_credentials' => $credentials,
            'google_drive_connected_at' => now(),
        ])->save();

        return $setting;
    }

    /**
     * Update only the encrypted token bundle after a refresh.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function updateGoogleDriveCredentials(array $credentials): Setting
    {
        $setting = $this->get();
        $setting->forceFill(['google_drive_credentials' => $credentials])->save();

        return $setting;
    }

    public function disconnectGoogleDrive(): Setting
    {
        $setting = $this->get();
        $setting->forceFill([
            'google_drive_account_email' => null,
            'google_drive_folder_id' => null,
            'google_drive_credentials' => null,
            'google_drive_connected_at' => null,
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

        ];
    }
}
