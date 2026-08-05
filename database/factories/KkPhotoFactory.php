<?php

namespace Database\Factories;

use App\Enums\PhotoType;
use App\Models\KartuKeluarga;
use App\Models\KkPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KkPhoto>
 */
class KkPhotoFactory extends Factory
{
    protected $model = KkPhoto::class;

    public function definition(): array
    {
        return [
            'kk_id' => KartuKeluarga::factory(),
            'original_filename' => fake()->uuid().'.jpg',
            'stored_filename' => fake()->uuid().'.jpg',
            'thumbnail_filename' => fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => fake()->numberBetween(50_000, 5_000_000),
            'sha256_hash' => fake()->sha256(),
            'storage_disk' => 'local',
            'storage_path' => 'kk/'.fake()->uuid().'.jpg',
            'photo_type' => PhotoType::KK_PHOTO->value,
            'is_active' => true,
            'uploaded_by' => null,
            'uploaded_at' => now(),
            'ocr_job_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
