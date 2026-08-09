<?php

namespace Database\Factories;

use App\Models\KartuKeluarga;
use App\Models\Rt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KartuKeluarga>
 */
class KartuKeluargaFactory extends Factory
{
    protected $model = KartuKeluarga::class;

    public function definition(): array
    {
        return [
            'kk_number' => fake()->unique()->numerify(str_repeat('#', 16)),
            'address' => fake()->streetAddress(),
            'rt_id' => Rt::factory(),
            'postal_code' => fake()->numerify('#####'),
            'notes' => null,
        ];
    }
}
