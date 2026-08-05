<?php

namespace Database\Seeders;

use App\Models\Occupation;
use Illuminate\Database\Seeder;

/**
 * Lookup master: pekerjaan. Common kelurahan occupations.
 * Idempotent via firstOrCreate on unique name.
 */
class OccupationSeeder extends Seeder
{
    private const OCCUPATIONS = [
        'Petani',
        'Pedagang',
        'Pegawai Negeri Sipil',
        'Karyawan Swasta',
        'Buruh',
        'Nelayan',
        'Ibu Rumah Tangga',
        'Pelajar/Mahasiswa',
        'Tukang',
        'Wiraswasta',
        'Pensiunan',
        'Lainnya',
    ];

    public function run(): void
    {
        foreach (self::OCCUPATIONS as $name) {
            Occupation::firstOrCreate(['name' => $name]);
        }
    }
}
