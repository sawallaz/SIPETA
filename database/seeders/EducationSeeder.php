<?php

namespace Database\Seeders;

use App\Models\Education;
use Illuminate\Database\Seeder;

/**
 * Lookup master: pendidikan. Idempotent via firstOrCreate on unique name.
 */
class EducationSeeder extends Seeder
{
    private const EDUCATIONS = [
        'Tidak/Belum Sekolah',
        'SD',
        'SMP',
        'SMA',
        'D1',
        'D2',
        'D3',
        'S1',
        'S2',
        'S3',
    ];

    public function run(): void
    {
        foreach (self::EDUCATIONS as $name) {
            Education::firstOrCreate(['name' => $name]);
        }
    }
}
