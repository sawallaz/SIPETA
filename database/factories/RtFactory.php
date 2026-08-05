<?php

namespace Database\Factories;

use App\Models\AreaUnit;
use App\Models\Rt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rt>
 */
class RtFactory extends Factory
{
    protected $model = Rt::class;

    public function definition(): array
    {
        return [
            'area_unit_id' => AreaUnit::inRandomOrder()->first()?->id ?? AreaUnit::factory()->create()->id,
            'number' => fake()->unique()->numerify('##'),
        ];
    }
}
