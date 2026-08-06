<?php

namespace Database\Factories;

use App\Enums\OcrJobStatus;
use App\Models\KartuKeluarga;
use App\Models\OcrJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OcrJob>
 */
class OcrJobFactory extends Factory
{
    protected $model = OcrJob::class;

    public function definition(): array
    {
        return [
            'kk_id' => KartuKeluarga::inRandomOrder()->first()?->id ?? KartuKeluarga::factory()->create()->id,
            'source_image_hash' => fake()->sha256(),
            'source_image_path' => 'ocr/'.fake()->uuid().'.jpg',
            'status' => fake()->randomElement(OcrJobStatus::persistable())->value,
            'confidence' => fake()->randomFloat(2, 0, 100),
            'raw_text' => null,
            'corrected_text' => null,
            'extracted_data' => null,
            'operator_id' => null,
            'reviewed_at' => null,
            'outcome' => null,
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
        ];
    }

    public function withoutKk(): static
    {
        return $this->state(fn () => ['kk_id' => null]);
    }
}
