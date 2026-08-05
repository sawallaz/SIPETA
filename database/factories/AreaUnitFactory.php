<?php

namespace Database\Factories;

use App\Models\AreaUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AreaUnit>
 */
class AreaUnitFactory extends Factory
{
    protected $model = AreaUnit::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->streetName(),
            'type' => fake()->randomElement(['lingkungan', 'rw']),
            'code' => fake()->unique()->bothify('?#'),
        ];
    }
}
