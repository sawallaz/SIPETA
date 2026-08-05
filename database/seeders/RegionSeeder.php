<?php

namespace Database\Seeders;

use App\Models\AreaUnit;
use App\Models\Rt;
use Illuminate\Database\Seeder;

/**
 * Region hierarchy: flexible area_units (Lingkungan | RW) + rts.
 * The area_units.type carries the local-admin label so the same schema serves
 * any kelurahan. Idempotent via firstOrCreate / unique composite (area_unit_id, number).
 */
class RegionSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedArea('Lingkungan I', 'lingkungan', 'I', ['01', '02', '03', '04', '05']);
        $this->seedArea('Lingkungan II', 'lingkungan', 'II', ['01', '02', '03', '04', '05']);
        $this->seedArea('RW 01', 'rw', '01', ['01', '02', '03', '04', '05', '06', '07', '08', '09']);
    }

    private function seedArea(string $name, string $type, string $code, array $rtNumbers): void
    {
        $area = AreaUnit::firstOrCreate(
            ['name' => $name],
            ['type' => $type, 'code' => $code],
        );

        foreach ($rtNumbers as $number) {
            Rt::firstOrCreate(
                ['area_unit_id' => $area->id, 'number' => $number],
                ['number' => $number],
            );
        }
    }
}
