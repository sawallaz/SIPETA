<?php

namespace App\Services;

use App\Exceptions\OcrProcessingException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OCR image preprocessing stage (Phase 5.3).
 *
 * Runs before OCR extraction and prepares the uploaded KK photo for the
 * future Tesseract stage:
 *
 *  1. Image validation — decode the image and enforce the resolution bounds
 *     from .ai/ocr.md §4.1 (min 800×600, rejected below; max 4000×4000,
 *     downscaled above).
 *  2. Orientation correction — EXIF orientation (tags 2–8) is applied via
 *     GD (imageflip / imagerotate), the form of orientation correction
 *     supported by the libraries available in this repository.
 *  3. Grayscale conversion — IMG_FILTER_GRAYSCALE (.ai/ocr.md §4.2 step 1).
 *  4. Resize/normalization — proportional downscale to the 4000×4000 cap
 *     (.ai/ocr.md §4.1) to control downstream processing time.
 *  5. Result tracking — a PreprocessResult DTO (path, dimensions, mean
 *     brightness, applied transforms, warnings, duration) plus a log line
 *     with the pipeline_stage=preprocess context (.ai/ocr.md §9).
 *
 * Transforms are recorded in the result's appliedTransforms list so later
 * stages can slot in. Denoise, automatic deskew (skew-angle detection),
 * adaptive binarization, and border removal (.ai/ocr.md §4.2 steps 2–5)
 * require a proper image-processing library (bilateral filter, projection
 * profiling, adaptive threshold) that is not present in this repository —
 * they remain for the OCR engine phase and are documented in docs/PHASE5.md
 * §5.3.3.
 *
 * Failures (undecodable image, resolution below minimum) raise
 * OcrProcessingException; the pipeline marks the job FAILED. Quality
 * warnings (e.g. brightness out of range, .ai/ocr.md §4.10) never block
 * processing — they are recorded in the result.
 */
class ImagePreprocessor
{
    /** Private local disk holding transient preprocessing intermediates (.ai/ocr.md §8). */
    public const DISK = 'ocr_temp';

    /** Minimum accepted resolution (width × height), .ai/ocr.md §4.1. */
    public const MIN_WIDTH = 800;

    public const MIN_HEIGHT = 600;

    /** Maximum dimension (width or height) before downscaling, .ai/ocr.md §4.1. */
    public const MAX_DIMENSION = 4000;

    /** Acceptable mean-brightness band (0–255), .ai/ocr.md §4.10. */
    public const BRIGHTNESS_MIN = 100;

    public const BRIGHTNESS_MAX = 200;

    /** JPEG magic bytes (FF D8 FF). */
    private const JPEG_SIGNATURE = "\xFF\xD8\xFF";

    /** PNG magic bytes (89 50 4E 47 0D 0A 1A 0A). */
    private const PNG_SIGNATURE = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";

    /** Target sample count for the brightness histogram (performance cap). */
    private const BRIGHTNESS_SAMPLES = 10_000;

    /** EXIF orientation values that require correction (1 = as-is). */
    private const ORIENTATION_MIN_CORRECTED = 2;

    public function __construct(private readonly FilesystemManager $filesystem) {}

    /**
     * Preprocess uploaded image bytes and store the result on the ocr_temp
     * disk.
     *
     * @throws OcrProcessingException when the image cannot be decoded or its
     *                                resolution is below the minimum; no file
     *                                is written and nothing is persisted
     */
    public function preprocess(string $bytes, string $sourcePath): PreprocessResult
    {
        $started = hrtime(true);

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            $this->log($bytes, null, 'failed', ['reason' => 'image could not be decoded']);

            throw new OcrProcessingException('Source image could not be decoded as a valid JPEG/PNG image.');
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Resolution gate (.ai/ocr.md §4.1) — deferred from the upload stage
        // to preprocessing (docs/PHASE5.md §5.1.3).
        if ($width < self::MIN_WIDTH || $height < self::MIN_HEIGHT) {
            imagedestroy($image);
            $message = sprintf(
                'Image resolution %dx%d is below the minimum %dx%d.',
                $width,
                $height,
                self::MIN_WIDTH,
                self::MIN_HEIGHT
            );
            $this->log($bytes, null, 'failed', ['reason' => 'resolution below minimum', 'width' => $width, 'height' => $height]);

            throw new OcrProcessingException($message);
        }

        $transforms = [];
        $warnings = [];

        // Orientation correction (EXIF) — the form supported by the current
        // libraries (exif + GD). Automatic skew detection is a later stage.
        $orientation = $this->readExifOrientation($bytes);

        if ($orientation >= self::ORIENTATION_MIN_CORRECTED) {
            $image = $this->applyExifOrientation($image, $orientation);
            $transforms[] = 'exif_orientation';
            $width = imagesx($image);
            $height = imagesy($image);
        }

        // Grayscale (.ai/ocr.md §4.2 step 1).
        imagefilter($image, IMG_FILTER_GRAYSCALE);
        $transforms[] = 'grayscale';

        // Resize/normalization: downscale past the 4000×4000 cap (.ai/ocr.md §4.1).
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            $scale = min(self::MAX_DIMENSION / $width, self::MAX_DIMENSION / $height);
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);

            $image = $resized;
            $transforms[] = 'resize';
            $width = $newWidth;
            $height = $newHeight;
        }

        // Quality metric (.ai/ocr.md §4.10): sampled mean brightness.
        $brightness = $this->meanBrightness($image);

        if ($brightness < self::BRIGHTNESS_MIN || $brightness > self::BRIGHTNESS_MAX) {
            $warnings[] = sprintf(
                'Mean brightness %.0f is outside the acceptable range %d–%d.',
                $brightness,
                self::BRIGHTNESS_MIN,
                self::BRIGHTNESS_MAX
            );
        }

        // Store the preprocessed image (PNG — lossless, keeps grayscale clean
        // for the future Tesseract stage).
        $outputName = pathinfo($sourcePath, PATHINFO_FILENAME).'-preprocessed.png';
        $png = $this->encodePng($image);
        imagedestroy($image);

        if (! $this->filesystem->disk(self::DISK)->put($outputName, $png)) {
            $this->log($bytes, null, 'failed', ['reason' => 'could not store preprocessed image', 'path' => $outputName]);

            throw new OcrProcessingException(sprintf('Preprocessed image could not be stored on the %s disk.', self::DISK));
        }

        $durationMs = round((hrtime(true) - $started) / 1_000_000, 2);

        $result = new PreprocessResult(
            path: $outputName,
            width: $width,
            height: $height,
            meanBrightness: round($brightness, 2),
            skewAngle: null,
            appliedTransforms: $transforms,
            warnings: $warnings,
            durationMs: $durationMs,
        );

        $this->log($bytes, $result, 'success');

        return $result;
    }

    /**
     * Read the EXIF orientation tag of a JPEG image. PNG images and images
     * without an EXIF segment report 1 (no correction needed).
     */
    private function readExifOrientation(string $bytes): int
    {
        if (! str_starts_with($bytes, self::JPEG_SIGNATURE) || ! function_exists('exif_read_data')) {
            return 1;
        }

        $temp = tempnam(sys_get_temp_dir(), 'sipeta-exif');

        if ($temp === false) {
            return 1;
        }

        try {
            file_put_contents($temp, $bytes);

            $exif = @exif_read_data($temp);

            return (int) ($exif['Orientation'] ?? 1);
        } catch (Throwable) {
            return 1;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Apply an EXIF orientation tag (2–8) to the image using GD's native
     * flip/rotate primitives. The caller destroys the returned resource.
     */
    private function applyExifOrientation(\GdImage $image, int $orientation): \GdImage
    {
        switch ($orientation) {
            case 2: // mirror horizontal
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3: // rotate 180°
                $image = imagerotate($image, 180, 0xFFFFFF);
                break;
            case 4: // mirror vertical
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5: // mirror horizontal + rotate 90° CW
                imageflip($image, IMG_FLIP_HORIZONTAL);
                $image = imagerotate($image, 270, 0xFFFFFF);
                break;
            case 6: // rotate 90° CW
                $image = imagerotate($image, 270, 0xFFFFFF);
                break;
            case 7: // mirror horizontal + rotate 270° CW
                imageflip($image, IMG_FLIP_HORIZONTAL);
                $image = imagerotate($image, 90, 0xFFFFFF);
                break;
            case 8: // rotate 270° CW
                $image = imagerotate($image, 90, 0xFFFFFF);
                break;
        }

        return $image;
    }

    /**
     * Sampled mean brightness (0–255) of a grayscale image. Samples at most
     * BRIGHTNESS_SAMPLES pixels spread evenly across the image so the metric
     * stays cheap even at the 4000×4000 cap.
     */
    private function meanBrightness(\GdImage $image): float
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width === 0 || $height === 0) {
            return 0.0;
        }

        $step = max(1, (int) ceil(sqrt(($width * $height) / self::BRIGHTNESS_SAMPLES)));
        $sum = 0;
        $count = 0;

        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                $sum += (imagecolorat($image, $x, $y) >> 16) & 0xFF;
                $count++;
            }
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    /**
     * Encode a GD image as PNG bytes.
     */
    private function encodePng(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        $png = ob_get_clean();

        if ($png === false || $png === '') {
            throw new OcrProcessingException('Preprocessed image could not be encoded as PNG.');
        }

        return $png;
    }

    /**
     * Pipeline-stage log line (.ai/ocr.md §9) with correlation context.
     */
    private function log(string $bytes, ?PreprocessResult $result, string $outcome, array $extra = []): void
    {
        $context = array_merge([
            'pipeline_stage' => 'preprocess',
            'image_hash' => substr(hash('sha256', $bytes), 0, 12),
            'outcome' => $outcome,
        ], $extra);

        if ($result !== null) {
            $context = array_merge($context, [
                'duration_ms' => $result->durationMs,
                'width' => $result->width,
                'height' => $result->height,
                'mean_brightness' => $result->meanBrightness,
                'transforms' => implode(',', $result->appliedTransforms),
                'warnings' => implode(',', $result->warnings),
            ]);
        }

        Log::info('OCR preprocessing '.$outcome, $context);
    }
}
