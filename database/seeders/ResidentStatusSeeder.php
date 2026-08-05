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
 *
 * NOTE: there is no standalone status table — the status lives on `penduduk`.
 * This seeder demonstrates all three status values with OBVIOUSLY-FAKE demo
 * records (NIK/KK prefixed 9000...) so they are easy to delete. It reuses the
 * masters/region seeded earlier in DatabaseSeeder. Idempotent: the demo KK
 * chain is deleted and recreated each run (children first to respect RESTRICT).
 *
 * Run only in dev/test.
 */
class ResidentStatusSeeder extends Seeder
{
    private const KK_PREFIX = '90000000';

    public function run(): void
    {
        $this->seedOne('9000000000000001', ResidentStatus::ACTIVE, FamilyRelation::KEPALA_KELUARGA, null);
        $this->seedOne('9000000000000002', ResidentStatus::PINDAH, FamilyRelation::ISTRI, 'PINDAH');
        $this->seedOne('9000000000000003', ResidentStatus::MENINGGAL, FamilyRelation::ANAK, 'MENINGGAL');
    }

    private function seedOne(string $nik, ResidentStatus $status, FamilyRelation $relation, ?string $suffix): void
    {
        $kkNumber = self::KK_PREFIX.$nik;

        $existing = KartuKeluarga::where('kk_number', $kkNumber)->first();
        if ($existing) {
            KkAnggota::where('kk_id', $existing->id)->delete();
            Penduduk::where('kk_id', $existing->id)->delete();
            $existing->delete();
        }

        $kk = KartuKeluarga::create([
            'kk_number' => $kkNumber,
            'address' => 'Jl. Demo Status '.$nik,
            'postal_code' => '90000',
            'notes' => 'DEMO ResidentStatusSeeder',
        ]);

        $penduduk = Penduduk::create([
            'kk_id' => $kk->id,
            'nik' => $nik,
            'full_name' => 'Demo '.$status->value.$suffix,
            'gender' => Gender::LAKI_LAKI->value,
            'birth_place' => 'Tanete',
            'birth_date' => '1990-01-01',
            'religion_id' => Religion::inRandomOrder()->first()->id,
            'education_id' => Education::inRandomOrder()->first()->id,
            'occupation_id' => Occupation::inRandomOrder()->first()->id,
            'marital_status' => MaritalStatus::KAWIN->value,
            'family_relation' => $relation->value,
            'blood_type' => BloodType::TIDAK_DIKETAHUI->value,
            'resident_status' => $status->value,
            'rt_id' => Rt::inRandomOrder()->first()->id,
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
