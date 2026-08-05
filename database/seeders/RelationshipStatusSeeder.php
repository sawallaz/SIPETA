<?php

namespace Database\Seeders;

use App\Enums\BloodType;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\KkAnggotaStatus;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\KkAnggota;
use App\Models\Occupation;
use App\Models\Penduduk;
use App\Models\Religion;
use App\Models\Rt;
use Illuminate\Database\Seeder;

/**
 * Family relation enum fixture (all FamilyRelation values).
 *
 * NOTE: there is no standalone family_relation table — the value lives on
 * `penduduk` and `kk_anggota`. This seeder demonstrates every relation value
 * with OBVIOUSLY-FAKE demo records (NIK prefixed 9100...) inside one demo KK.
 * Idempotent: the demo KK chain is deleted and recreated each run (children
 * first to respect RESTRICT).
 */
class RelationshipStatusSeeder extends Seeder
{
    private const KK_NUMBER = '9100000091000000';

    public function run(): void
    {
        $existing = KartuKeluarga::where('kk_number', self::KK_NUMBER)->first();
        if ($existing) {
            KkAnggota::where('kk_id', $existing->id)->delete();
            Penduduk::where('kk_id', $existing->id)->delete();
            $existing->delete();
        }

        $kk = KartuKeluarga::create([
            'kk_number' => self::KK_NUMBER,
            'address' => 'Jl. Demo Relation',
            'postal_code' => '91000',
            'notes' => 'DEMO RelationshipStatusSeeder',
        ]);

        $baseYear = 1980;
        foreach (FamilyRelation::cases() as $i => $relation) {
            $nik = '910000000000'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);

            $penduduk = Penduduk::create([
                'kk_id' => $kk->id,
                'nik' => $nik,
                'full_name' => 'Demo '.$relation->value,
                'gender' => ($i % 2 === 0) ? Gender::LAKI_LAKI->value : Gender::PEREMPUAN->value,
                'birth_place' => 'Tanete',
                'birth_date' => (string) ($baseYear + $i).'-01-01',
                'religion_id' => Religion::inRandomOrder()->first()->id,
                'education_id' => Education::inRandomOrder()->first()->id,
                'occupation_id' => Occupation::inRandomOrder()->first()->id,
                'marital_status' => MaritalStatus::KAWIN->value,
                'family_relation' => $relation->value,
                'blood_type' => BloodType::TIDAK_DIKETAHUI->value,
                'resident_status' => ResidentStatus::ACTIVE->value,
                'rt_id' => Rt::inRandomOrder()->first()->id,
                'notes' => 'DEMO',
            ]);

            KkAnggota::create([
                'kk_id' => $kk->id,
                'penduduk_id' => $penduduk->id,
                'family_relation' => $relation->value,
                'status' => KkAnggotaStatus::AKTIF->value,
                'effective_date' => '2024-01-01',
                'end_date' => null,
            ]);
        }
    }
}
