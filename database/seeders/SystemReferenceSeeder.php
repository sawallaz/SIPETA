<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * System reference data required for basic SIPETA operation.
 * Idempotent master data seeders (Settings, Religion, Education, Occupation).
 * Does NOT seed any user credentials.
 */
class SystemReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            ReligionSeeder::class,
            EducationSeeder::class,
            OccupationSeeder::class,
        ]);
    }
}
