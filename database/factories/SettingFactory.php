<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    public function definition(): array
    {
        return [
            'kelurahan_name' => 'Kelurahan Tanete',
            'kecamatan_name' => 'Tanete',
            'kabupaten_name' => 'Kabupaten Barru',
            'province_name' => 'Sulawesi Selatan',
            'logo_path' => null,
            'backup_path' => storage_path('app/backups'),
        ];
    }

    /**
     * Force the singleton row id = 1 (used by tests that need the seeded settings).
     */
    public function singleton(): static
    {
        return $this->state(fn () => ['id' => 1]);
    }
}
