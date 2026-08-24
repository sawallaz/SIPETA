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
 * Resident status enum fixture (ACTIVE / PINDAH / MENINGGAL).
 * KK number dibuat singkat (16 digit) sesuai schema.
 */
class ResidentStatusSeeder extends Seeder
{
    private const KK_PREFIX = '900000000000';

    public function run(): void
    {
        $religion = Religion::firstOrCreate(['name' => 'Islam']);
        $education = Education::firstOrCreate(['name' => 'SMA']);
        $occupation = Occupation::firstOrCreate(['name' => 'Wiraswasta']);
        $rt = Rt::first();

        $this->seedOne('000001', ResidentStatus::ACTIVE, FamilyRelation::KEPALA_KELUARGA, null, $religion, $education, $occupation, $rt);
        $this->seedOne('000002', ResidentStatus::PINDAH, FamilyRelation::ISTRI, 'PINDAH', $religion, $education, $occupation, $rt);
        $this->seedOne('000003', ResidentStatus::MENINGGAL, FamilyRelation::ANAK, 'MENINGGAL', $religion, $education, $occupation, $rt);
    }

    private function seedOne(string $nikSuffix, ResidentStatus $status, FamilyRelation $relation, ?string $suffix, Religion $religion, Education $education, Occupation $occupation, Rt $rt): void
    {
        $nik = '900000000000'.$nikSuffix;
        $kkNumber = self::KK_PREFIX.$nikSuffix;

        $existing = KartuKeluarga::where('kk_number', $kkNumber)->first();
        if ($existing) {
            KkAnggota::where('kk_id', $existing->id)->delete();
            Penduduk::where('kk_id', $existing->id)->delete();
            $existing->delete();
        }

        $kk = KartuKeluarga::create([
            'kk_number' => $kkNumber,
            'address' => 'Jl. Demo Status '.$nikSuffix,
            'postal_code' => '90000',
            'notes' => 'DEMO ResidentStatusSeeder',
        ]);

        $penduduk = Penduduk::create([
            'kk_id' => $kk->id,
            'nik' => $nik,
            'full_name' => 'Demo '.$status->value.($suffix ?? ''),
            'gender' => Gender::LAKI_LAKI->value,
            'birth_place' => 'Tanete',
            'birth_date' => '1990-01-01',
            'religion_id' => $religion->id,
            'education_id' => $education->id,
            'occupation_id' => $occupation->id,
            'marital_status' => MaritalStatus::KAWIN->value,
            'family_relation' => $relation->value,
            'blood_type' => BloodType::TIDAK_DIKETAHUI->value,
            'resident_status' => $status->value,
            'rt_id' => $rt->id,
            'moved_at' => $status === ResidentStatus::PINDAH ? now()->toDateString() : null,
            'moved_destination' => $status === ResidentStatus::PINDAH ? 'Lain Kota' : null,
            'moved_note' => $status === ResidentStatus::PINDAH ? 'Demo pindah' : null,
            'deceased_at' => $status === ResidentStatus::MENINGGAL ? now()->toDateString() : null,
            'deceased_note' => $status === ResidentStatus::MENINGGAL ? 'Demo meninggal' : null,
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
