<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Singleton settings row. Enforced by Service layer; this seeder only provisions it.
 * Idempotent via updateOrCreate on the fixed id = 1.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::updateOrCreate(
            ['id' => 1],
            [
                'kelurahan_name' => env('SETTINGS_KELURAHAN_NAME', 'Kelurahan Tanete'),
                'kecamatan_name' => env('SETTINGS_KECAMATAN_NAME', 'Tanete'),
                'kabupaten_name' => env('SETTINGS_KABUPATEN_NAME', 'Barru'),
                'province_name' => env('SETTINGS_PROVINCE_NAME', 'Sulawesi Selatan'),
                'logo_path' => env('SETTINGS_LOGO_PATH'),
                'backup_path' => env('SETTINGS_BACKUP_PATH', storage_path('app/backups')),
            ],
        );
    }
}
