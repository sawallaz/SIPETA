<?php

namespace Database\Factories;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupLog>
 */
class BackupLogFactory extends Factory
{
    protected $model = BackupLog::class;

    public function definition(): array
    {
        return [
            'filename' => 'backup_'.fake()->unique()->numerify('##########_######').'.zip',
            'backup_type' => fake()->randomElement(BackupType::cases())->value,
            'backup_status' => fake()->randomElement(BackupStatus::cases())->value,
            'backup_size' => fake()->numberBetween(10_000, 50_000_000),
            'operator_id' => null,
            'started_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'finished_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'message' => null,
        ];
    }
}
