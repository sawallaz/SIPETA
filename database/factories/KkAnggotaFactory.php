<?php

namespace Database\Factories;

use App\Enums\FamilyRelation;
use App\Enums\KkAnggotaStatus;
use App\Models\KkAnggota;
use App\Models\Penduduk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KkAnggota>
 */
class KkAnggotaFactory extends Factory
{
    protected $model = KkAnggota::class;

    public function definition(): array
    {
        $penduduk = Penduduk::factory()->create();

        return [
            'kk_id' => $penduduk->kk_id,
            'penduduk_id' => $penduduk->id,
            'family_relation' => fake()->randomElement(FamilyRelation::cases())->value,
            'status' => KkAnggotaStatus::AKTIF->value,
            'effective_date' => fake()->date(),
            'end_date' => null,
        ];
    }
}
