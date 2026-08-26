<?php

namespace Tests\Feature\Phase5;

use App\Enums\OcrJobStatus;
use App\Exceptions\OcrProcessingException;
use App\Models\OcrJob;
use App\Services\ImagePreprocessor;
use App\Services\KkDocumentUploadService;
use App\Services\OcrProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 5.3 — OCR image preprocessing.
 *
 * Proves the ImagePreprocessor (driven through the OcrProcessingService
 * pipeline) validates the image (decode + minimum resolution), applies EXIF
 * orientation correction, converts to grayscale, downscales oversized images
 * to the 4000×4000 cap, stores a preprocessed PNG on the ocr_temp disk, and
 * exposes a PreprocessResult with tracking metadata (dimensions, brightness,
 * transforms, warnings, duration). Failures are handled by persisting the
 * job as FAILED; quality warnings never block processing.
 */
class ImagePreprocessorTest extends TestCase
{
    use RefreshDatabase;

    /** PNG magic bytes (89 50 4E 47 0D 0A 1A 0A). */
    private const PNG_SIGNATURE = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";

    private OcrProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OcrProcessingService::class);
        Storage::fake(KkDocumentUploadService::DISK);
        Storage::fake(ImagePreprocessor::DISK);
    }

    public function test_valid_image_is_preprocessed_and_job_reaches_processing(): void
    {
        $job = $this->uploadBytes($this->makePng(800, 600, 180, 180, 180));

        $result = $this->service->start($job);

        $this->assertSame(OcrJobStatus::PROCESSING, $result->status);

        $preprocessed = $this->service->preprocessResult();
        $this->assertNotNull($preprocessed);
        // 800×600 source is upscaled 2× by the smart upscaler (width < 1800).
        $this->assertSame(1600, $preprocessed->width);
        $this->assertSame(1200, $preprocessed->height);
        $this->assertContains('grayscale', $preprocessed->appliedTransforms);
        $this->assertContains('upscale', $preprocessed->appliedTransforms);
        $this->assertNotContains('exif_orientation', $preprocessed->appliedTransforms);
        $this->assertNull($preprocessed->skewAngle);
        $this->assertGreaterThan(0, $preprocessed->durationMs);
        $this->assertSame([], $preprocessed->warnings);
    }

    public function test_preprocessed_output_is_generated_on_ocr_temp_disk(): void
    {
        $job = $this->uploadBytes($this->makePng(800, 600, 180, 180, 180));

        $this->service->start($job);

        $preprocessed = $this->service->preprocessResult();
        $this->assertNotNull($preprocessed);
        $this->assertStringEndsWith('-preprocessed.png', $preprocessed->path);
        $this->assertTrue(Storage::disk(ImagePreprocessor::DISK)->exists($preprocessed->path));

        $output = Storage::disk(ImagePreprocessor::DISK)->get($preprocessed->path);
        $this->assertIsString($output);
        $this->assertStringStartsWith(self::PNG_SIGNATURE, $output);

        $decoded = imagecreatefromstring((string) $output);
        $this->assertNotFalse($decoded);
        // 800×600 source is upscaled 2× by the smart upscaler (width < 1800).
        $this->assertSame(1600, imagesx($decoded));
        $this->assertSame(1200, imagesy($decoded));
        imagedestroy($decoded);
    }

    public function test_corrupt_image_is_rejected_and_job_fails(): void
    {
        Storage::disk(KkDocumentUploadService::DISK)->put('ocr/corrupt.png', self::PNG_SIGNATURE.'not a real image body');

        $job = OcrJob::factory()->create([
            'status' => OcrJobStatus::PENDING,
            'source_image_path' => 'ocr/corrupt.png',
        ]);

        try {
            $this->service->start($job);
            $this->fail('Expected OcrProcessingException for an undecodable image.');
        } catch (OcrProcessingException $e) {
            $this->assertStringContainsString('could not be decoded', $e->getMessage());
        }

        $failed = $job->fresh();
        $this->assertSame(OcrJobStatus::FAILED, $failed->status);
        $this->assertStringContainsString('could not be decoded', (string) $failed->error_message);
        $this->assertNotNull($failed->finished_at);

        $this->assertSame([], Storage::disk(ImagePreprocessor::DISK)->allFiles());
    }

    public function test_resolution_below_minimum_fails_the_job(): void
    {
        $job = $this->uploadBytes($this->makePng(400, 300, 180, 180, 180), 'kk-small.png');

        try {
            $this->service->start($job);
            $this->fail('Expected OcrProcessingException for a below-minimum resolution image.');
        } catch (OcrProcessingException $e) {
            $this->assertStringContainsString('below the minimum', $e->getMessage());
        }

        $failed = $job->fresh();
        $this->assertSame(OcrJobStatus::FAILED, $failed->status);
        $this->assertStringContainsString('below the minimum', (string) $failed->error_message);
        $this->assertNotNull($failed->error_message);
        $this->assertNotNull($failed->finished_at);

        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id,
            'status' => OcrJobStatus::FAILED->value,
        ]);

        $this->assertSame([], Storage::disk(ImagePreprocessor::DISK)->allFiles());
    }

    public function test_oversized_image_is_downscaled_to_max_dimension(): void
    {
        $job = $this->uploadBytes($this->makePng(4500, 2000, 180, 180, 180), 'kk-large.png');

        $this->service->start($job);

        $preprocessed = $this->service->preprocessResult();
        $this->assertNotNull($preprocessed);
        $this->assertLessThanOrEqual(4000, $preprocessed->width);
        $this->assertLessThanOrEqual(4000, $preprocessed->height);
        $this->assertContains('resize', $preprocessed->appliedTransforms);

        // Aspect ratio preserved: 4500:2000 = 2.25.
        $this->assertEqualsWithDelta(2.25, $preprocessed->width / $preprocessed->height, 0.01);
    }

    public function test_exif_orientation_is_applied_to_jpeg(): void
    {
        // Orientation tag 6 = rotate 90° CW: an 800×600 source becomes 600×800.
        $job = $this->uploadBytes($this->makeJpegWithExifOrientation(6, 800, 600), 'kk-rotated.jpg', 'image/jpeg');

        $this->service->start($job);

        $preprocessed = $this->service->preprocessResult();
        $this->assertNotNull($preprocessed);
        // Orientation 6 rotates 800×600 → 600×800; smart upscaler then doubles to 1200×1600.
        $this->assertSame(1200, $preprocessed->width);
        $this->assertSame(1600, $preprocessed->height);
        $this->assertContains('exif_orientation', $preprocessed->appliedTransforms);
        $this->assertContains('upscale', $preprocessed->appliedTransforms);
    }

    public function test_dark_image_records_brightness_warning_but_still_processes(): void
    {
        $job = $this->uploadBytes($this->makePng(800, 600, 0, 0, 0));

        $result = $this->service->start($job);

        $this->assertSame(OcrJobStatus::PROCESSING, $result->status);

        $preprocessed = $this->service->preprocessResult();
        $this->assertNotNull($preprocessed);
        $this->assertLessThan(100, (float) $preprocessed->meanBrightness);
        $this->assertNotEmpty($preprocessed->warnings);
        $this->assertStringContainsString('brightness', $preprocessed->warnings[0]);
        $this->assertTrue(Storage::disk(ImagePreprocessor::DISK)->exists($preprocessed->path));
    }

    /**
     * Upload raw image bytes through the real upload service (Phase 5.1
     * gate) so the full pipeline is exercised end to end.
     */
    private function uploadBytes(string $bytes, string $name = 'kk-scan.png', string $mime = 'image/png'): OcrJob
    {
        $temp = tempnam(sys_get_temp_dir(), 'sipeta-5-3').'.png';
        file_put_contents($temp, $bytes);

        return app(KkDocumentUploadService::class)->upload(new UploadedFile($temp, $name, $mime, null, true));
    }

    /**
     * Generate a solid-color PNG with GD.
     */
    private function makePng(int $width, int $height, int $red, int $green, int $blue): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * Generate a real JPEG (GD) with an EXIF APP1 segment carrying the given
     * orientation tag (2–8).
     */
    private function makeJpegWithExifOrientation(int $orientation, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 180, 180, 180));

        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        // TIFF little-endian header + IFD0 with a single Orientation (0x0112,
        // SHORT) entry, next-IFD offset 0.
        $ifd0 = "\x01\x00"
            ."\x12\x01"
            ."\x03\x00"
            ."\x01\x00\x00\x00"
            .pack('v', $orientation)."\x00\x00"
            ."\x00\x00\x00\x00";
        $tiff = "II*\x00\x08\x00\x00\x00".$ifd0;
        $app1 = "\xFF\xE1".pack('n', strlen('Exif'.chr(0).chr(0).$tiff) + 2).'Exif'.chr(0).chr(0).$tiff;

        // SOI + injected APP1 + original segments (JFIF APP0 onwards).
        return "\xFF\xD8".$app1.substr($jpeg, 2);
    }
}
