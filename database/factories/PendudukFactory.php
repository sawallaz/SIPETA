<?php

namespace Database\Factories;

use App\Enums\BloodType;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\Occupation;
use App\Models\Penduduk;
use App\Models\Religion;
use App\Models\Rt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Penduduk>
 */
class PendudukFactory extends Factory
{
    protected $model = Penduduk::class;

    public function definition(): array
    {
        return [
            'kk_id' => KartuKeluarga::factory(),
            'nik' => fake()->unique()->numerify(str_repeat('#', 16)),
            'full_name' => fake()->name(),
            'gender' => fake()->randomElement(Gender::cases())->value,
            'birth_place' => fake()->city(),
            'birth_date' => fake()->dateTimeBetween('-80 years', '-1 year')->format('Y-m-d'),
            'religion_id' => Religion::inRandomOrder()->first()?->id ?? Religion::factory()->create()->id,
            'education_id' => Education::inRandomOrder()->first()?->id ?? Education::factory()->create()->id,
            'occupation_id' => Occupation::inRandomOrder()->first()?->id ?? Occupation::factory()->create()->id,
            'marital_status' => fake()->randomElement(MaritalStatus::cases())->value,
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
            'blood_type' => fake()->randomElement(BloodType::cases())->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'rt_id' => Rt::inRandomOrder()->first()?->id ?? Rt::factory()->create()->id,
            'moved_at' => null,
            'moved_destination' => null,
            'moved_note' => null,
            'deceased_at' => null,
            'deceased_note' => null,
            'notes' => null,
        ];
    }

    public function moved(): static
    {
        return $this->state(fn () => [
            'resident_status' => ResidentStatus::PINDAH->value,
            'moved_at' => fake()->date(),
            'moved_destination' => fake()->city(),
            'moved_note' => fake()->sentence(),
        ]);
    }

    public function deceased(): static
    {
        return $this->state(fn () => [
            'resident_status' => ResidentStatus::MENINGGAL->value,
            'deceased_at' => fake()->date(),
            'deceased_note' => fake()->sentence(),
        ]);
    }
}
