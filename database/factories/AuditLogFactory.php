<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\KartuKeluarga;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        $loggable = KartuKeluarga::factory()->create();

        return [
            'loggable_type' => $loggable->getMorphClass(),
            'loggable_id' => $loggable->id,
            'actor_type' => null,
            'actor_id' => null,
            'event' => fake()->randomElement(['created', 'updated', 'status_changed', 'restored']),
            'old_values' => null,
            'new_values' => ['sample' => fake()->word()],
            'ip_address' => null,
        ];
    }
}
