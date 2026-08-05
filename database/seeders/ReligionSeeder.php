<?php

namespace Database\Seeders;

use App\Models\Religion;
use Illuminate\Database\Seeder;

/**
 * Lookup master: agama. Idempotent via firstOrCreate on unique name.
 */
class ReligionSeeder extends Seeder
{
    private const RELIGIONS = [
        'Islam',
        'Kristen',
        'Katolik',
        'Hindu',
        'Buddha',
        'Konghucu',
        'Lainnya',
    ];

    public function run(): void
    {
        foreach (self::RELIGIONS as $name) {
            Religion::firstOrCreate(['name' => $name]);
        }
    }
}
