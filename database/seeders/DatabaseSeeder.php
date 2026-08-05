<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Phase 2 seeder orchestration. Idempotent seeders only; pure orchestration here.
 *
 * Order: singleton + masters first (no FK deps), then region, admin user,
 * then the demo fixtures that self-heal their own FK chain.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            ReligionSeeder::class,
            EducationSeeder::class,
            OccupationSeeder::class,
            RegionSeeder::class,
            AdminUserSeeder::class,
            ResidentStatusSeeder::class,
            RelationshipStatusSeeder::class,
        ]);
    }
}
