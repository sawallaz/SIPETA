<?php

namespace App\Services;

use App\Exceptions\OcrProcessingException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OCR image preprocessing stage (Phase 5.3).
 *
 * Pipeline:
 *
 * 1. Decode image
 * 2. Validate minimum resolution
 * 3. Correct EXIF orientation
 * 4. Convert to grayscale
 * 5. Resize oversized image
 * 6. Normalize brightness
 * 7. Enhance contrast
 * 8. Sharpen text edges
 * 9. Measure final brightness
 * 10. Store lossless PNG for Tesseract
 */
class ImagePreprocessor
{
    /**
     * Private local disk holding transient preprocessing intermediates.
     */
    public const DISK = 'ocr_temp';

    /**
     * Minimum accepted resolution.
     */
    public const MIN_WIDTH = 800;

    public const MIN_HEIGHT = 600;

    /**
     * Maximum dimension before downscaling.
     */
    public const MAX_DIMENSION = 4000;

    /**
     * Minimum width threshold for smart upscaling of low-resolution images.
     */
    public const MIN_UPSCALE_WIDTH = 1800;

    /**
     * Acceptable mean-brightness band (100–200).
     */
    public const BRIGHTNESS_MIN = 100;

    public const BRIGHTNESS_MAX = 200;

    /**
     * JPEG magic bytes.
     */
    private const JPEG_SIGNATURE = "\xFF\xD8\xFF";

    /**
     * PNG magic bytes.
     */
    private const PNG_SIGNATURE = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";

    /**
     * Target sample count for brightness measurement.
     */
    private const BRIGHTNESS_SAMPLES = 10_000;

    /**
     * EXIF orientation values that require correction.
     */
    private const ORIENTATION_MIN_CORRECTED = 2;

    public function __construct(
        private readonly FilesystemManager $filesystem,
    ) {}

    /**
     * Preprocess uploaded image bytes and store the result on the
     * ocr_temp disk.
     *
     * @throws OcrProcessingException
     */
    public function preprocess(
        string $bytes,
        string $sourcePath,
    ): PreprocessResult {
        @ini_set('memory_limit', '512M');
        $started = hrtime(true);

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            $this->log(
                $bytes,
                null,
                'failed',
                [
                    'reason' => 'image could not be decoded',
                ],
            );

            throw new OcrProcessingException(
                'Source image could not be decoded as a valid JPEG/PNG image.',
            );
        }

        $width = imagesx($image);
        $height = imagesy($image);

        /*
         * ============================================================
         * 1. RESOLUTION GATE
         * ============================================================
         */

        if (
            $width < self::MIN_WIDTH
            || $height < self::MIN_HEIGHT
        ) {
            imagedestroy($image);

            $message = sprintf(
                'Image resolution %dx%d is below the minimum %dx%d.',
                $width,
                $height,
                self::MIN_WIDTH,
                self::MIN_HEIGHT,
            );

            $this->log(
                $bytes,
                null,
                'failed',
                [
                    'reason' => 'resolution below minimum',
                    'width' => $width,
                    'height' => $height,
                ],
            );

            throw new OcrProcessingException($message);
        }

        $transforms = [];
        $warnings = [];

        /*
         * ============================================================
         * 2. EXIF ORIENTATION
         * ============================================================
         */

        $orientation = $this->readExifOrientation($bytes);

        if ($orientation >= self::ORIENTATION_MIN_CORRECTED) {
            $image = $this->applyExifOrientation(
                $image,
                $orientation,
            );

            $transforms[] = 'exif_orientation';

            $width = imagesx($image);
            $height = imagesy($image);
        }

        /*
         * ============================================================
         * 3. GRAYSCALE
         * ============================================================
         */

        if (! imagefilter($image, IMG_FILTER_GRAYSCALE)) {
            imagedestroy($image);

            throw new OcrProcessingException(
                'Image grayscale preprocessing failed.',
            );
        }

        $transforms[] = 'grayscale';

        /*
         * ============================================================
         * 4. RESIZE
         * ============================================================
         */

        if (
            $width > self::MAX_DIMENSION
            || $height > self::MAX_DIMENSION
        ) {
            $scale = min(
                self::MAX_DIMENSION / $width,
                self::MAX_DIMENSION / $height,
            );

            $newWidth = max(
                1,
                (int) round($width * $scale),
            );

            $newHeight = max(
                1,
                (int) round($height * $scale),
            );

            $resized = imagecreatetruecolor(
                $newWidth,
                $newHeight,
            );

            if ($resized === false) {
                imagedestroy($image);

                throw new OcrProcessingException(
                    'Image could not be resized for OCR processing.',
                );
            }

            imagecopyresampled(
                $resized,
                $image,
                0,
                0,
                0,
                0,
                $newWidth,
                $newHeight,
                $width,
                $height,
            );

            imagedestroy($image);

            $image = $resized;
            $width = $newWidth;
            $height = $newHeight;

            $transforms[] = 'resize';
        }

        /*
         * ============================================================
         * 4b. SMART UPSCALER — Low-resolution image enhancement
         * ============================================================
         *
         * Jika gambar memiliki lebar < MIN_UPSCALE_WIDTH (1800px) setelah
         * downscale, perbesar 2x agar Tesseract dapat membaca detail huruf
         * pada foto HP resolusi rendah atau hasil scan buram.
         *
         * imagecopyresampled() pada GD setara Bicubic interpolation yang
         * menghasilkan hasil lebih halus daripada imagecopyresized().
         */
        if ($width < self::MIN_UPSCALE_WIDTH) {
            $upW = min((int) round($width * 2), self::MAX_DIMENSION);
            $upH = min((int) round($height * 2), self::MAX_DIMENSION);

            $upscaled = imagecreatetruecolor($upW, $upH);

            if ($upscaled !== false) {
                imagecopyresampled(
                    $upscaled,
                    $image,
                    0, 0, 0, 0,
                    $upW, $upH,
                    $width, $height,
                );

                imagedestroy($image);
                $image   = $upscaled;
                $width   = $upW;
                $height  = $upH;
                $transforms[] = 'upscale';
            }
        }

        /*
         * ============================================================
         * 5. MEASURE BRIGHTNESS BEFORE ENHANCEMENT
         * ============================================================
         */

        $brightnessBefore = $this->meanBrightness($image);

        /*
         * ============================================================
         * 6. OCR IMAGE IMPROVEMENT
         * ============================================================
         *
         * Kasus foto sebelumnya:
         *
         *   Mean brightness = 231
         *
         * Kode lama hanya mencatat warning lalu mengirim gambar
         * mentah ke Tesseract. improveForOcr() benar-benar
         * mengoreksi brightness, menaikkan kontras, dan
         * mempertegas tepi teks (sharpen) sebelum OCR.
         */

        $enhancementTransforms = $this->improveForOcr(
            $image,
            $brightnessBefore,
        );

        $transforms = array_merge(
            $transforms,
            $enhancementTransforms,
        );

        /*
         * ============================================================
         * 7. FINAL BRIGHTNESS CHECK
         * ============================================================
         */

        $brightness = $this->meanBrightness($image);

        if (
            $brightness < self::BRIGHTNESS_MIN
            || $brightness > self::BRIGHTNESS_MAX
        ) {
            $warnings[] = sprintf(
                'Mean brightness %.0f is outside the acceptable range %d–%d after preprocessing.',
                $brightness,
                self::BRIGHTNESS_MIN,
                self::BRIGHTNESS_MAX,
            );
        }

        /*
         * ============================================================
         * 10. STORE PREPROCESSED IMAGE
         * ============================================================
         *
         * PNG dipakai agar hasil preprocessing tidak mengalami
         * compression loss tambahan sebelum masuk Tesseract.
         */

        $outputName = pathinfo(
            $sourcePath,
            PATHINFO_FILENAME,
        ).'-preprocessed.png';

        $png = $this->encodePng($image);

        // Simpan debug image untuk verifikasi visual kualitas pra-pemrosesan OCR
        @file_put_contents(storage_path('app/debug_ocr_processed.png'), $png);

        imagedestroy($image);

        if (
            ! $this->filesystem
                ->disk(self::DISK)
                ->put($outputName, $png)
        ) {
            $this->log(
                $bytes,
                null,
                'failed',
                [
                    'reason' => 'could not store preprocessed image',
                    'path' => $outputName,
                ],
            );

            throw new OcrProcessingException(
                sprintf(
                    'Preprocessed image could not be stored on the %s disk.',
                    self::DISK,
                ),
            );
        }

        $durationMs = round(
            (hrtime(true) - $started) / 1_000_000,
            2,
        );

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

        $this->log(
            $bytes,
            $result,
            'success',
            [
                'original_brightness' => round(
                    $brightnessBefore,
                    2,
                ),
            ],
        );

        return $result;
    }

    /**
     * Improve an already-grayscale image for OCR readability.
     *
     * Applies, in order:
     *   1. Brightness normalization toward ~160 (the middle of the
     *      100–200 band) — this is what pulls an over-bright photo
     *      (e.g. 231) down into a readable range. Capped at ±90 so a
     *      genuinely dark/bright photo is not destroyed.
     *   2. Light contrast boost so text stands against the background.
     *   3. Light sharpening via a 3×3 convolution kernel.
     *
     * The original uploaded photo is never modified — this only affects
     * the transient copy stored on the ocr_temp disk.
     *
     * NOTE: binarization/extreme contrast was evaluated on the sample KK
     * and raised mean confidence only marginally (≈39) while dropping the
     * captured KK number, so it is deliberately NOT applied here.
     *
     * @return list<string> transform names applied
     */
    private function improveForOcr(
        \GdImage $image,
        float $brightness,
    ): array {
        $transforms = [];

        /*
         * 1. Normalisasi brightness.
         *
         * Target ~160 (tengah band 100–200). Foto terlalu terang (>200)
         * diturunkan; foto terlalu gelap (<100) dinaikkan.
         * Dibatasi ±90 agar tidak merusak gambar.
         */
        if ($brightness < self::BRIGHTNESS_MIN || $brightness > self::BRIGHTNESS_MAX) {
            $target = 160;
            $correction = (int) round($target - $brightness);
            $correction = max(-90, min(90, $correction));

            if ($correction !== 0) {
                imagefilter(
                    $image,
                    IMG_FILTER_BRIGHTNESS,
                    $correction,
                );

                $transforms[] = $correction < 0
                    ? 'brightness_down'
                    : 'brightness_up';
            }
        }

        /*
         * 2. Tingkatkan kontras.
         *
         * GD: -100 = kontras sangat tinggi, 0 = normal.
         * Nilai -15 memperjelas cetakan huruf tipis/dot-matrix pada kertas KK.
         */
        imagefilter(
            $image,
            IMG_FILTER_CONTRAST,
            -15,
        );

        $transforms[] = 'contrast';

        /*
         * 3. Sharpening filter untuk mempertegas garis tabel dan angka NIK.
         */
        $sharpenMatrix = [
            [-1, -1, -1],
            [-1, 16, -1],
            [-1, -1, -1],
        ];
        imageconvolution($image, $sharpenMatrix, 8, 0);

        $transforms[] = 'sharpen';

        return $transforms;
    }

    /**
     * Read the EXIF orientation tag of a JPEG image.
     *
     * PNG images and images without an EXIF segment report 1.
     */
    private function readExifOrientation(
        string $bytes,
    ): int {
        if (
            ! str_starts_with(
                $bytes,
                self::JPEG_SIGNATURE,
            )
            || ! function_exists('exif_read_data')
        ) {
            return 1;
        }

        $temp = tempnam(
            sys_get_temp_dir(),
            'sipeta-exif',
        );

        if ($temp === false) {
            return 1;
        }

        try {
            file_put_contents(
                $temp,
                $bytes,
            );

            $exif = @exif_read_data($temp);

            return (int) (
                $exif['Orientation'] ?? 1
            );
        } catch (Throwable) {
            return 1;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Apply an EXIF orientation tag.
     */
    private function applyExifOrientation(
        \GdImage $image,
        int $orientation,
    ): \GdImage {
        switch ($orientation) {
            case 2:
                imageflip(
                    $image,
                    IMG_FLIP_HORIZONTAL,
                );
                break;

            case 3:
                $image = imagerotate(
                    $image,
                    180,
                    0xFFFFFF,
                );
                break;

            case 4:
                imageflip(
                    $image,
                    IMG_FLIP_VERTICAL,
                );
                break;

            case 5:
                imageflip(
                    $image,
                    IMG_FLIP_HORIZONTAL,
                );

                $image = imagerotate(
                    $image,
                    270,
                    0xFFFFFF,
                );
                break;

            case 6:
                $image = imagerotate(
                    $image,
                    270,
                    0xFFFFFF,
                );
                break;

            case 7:
                imageflip(
                    $image,
                    IMG_FLIP_HORIZONTAL,
                );

                $image = imagerotate(
                    $image,
                    90,
                    0xFFFFFF,
                );
                break;

            case 8:
                $image = imagerotate(
                    $image,
                    90,
                    0xFFFFFF,
                );
                break;
        }

        return $image;
    }

    /**
     * Calculate sampled mean brightness.
     *
     * The image is already grayscale at this stage.
     */
    private function meanBrightness(
        \GdImage $image,
    ): float {
        $width = imagesx($image);
        $height = imagesy($image);

        if (
            $width === 0
            || $height === 0
        ) {
            return 0.0;
        }

        $step = max(
            1,
            (int) ceil(
                sqrt(
                    ($width * $height)
                    / self::BRIGHTNESS_SAMPLES,
                ),
            ),
        );

        $sum = 0;
        $count = 0;

        for (
            $y = 0;
            $y < $height;
            $y += $step
        ) {
            for (
                $x = 0;
                $x < $width;
                $x += $step
            ) {
                $pixel = imagecolorat(
                    $image,
                    $x,
                    $y,
                );

                $sum += ($pixel >> 16) & 0xFF;
                $count++;
            }
        }

        return $count > 0
            ? $sum / $count
            : 0.0;
    }

    /**
     * Encode a GD image as PNG bytes.
     */
    private function encodePng(
        \GdImage $image,
    ): string {
        ob_start();

        imagepng($image);

        $png = ob_get_clean();

        if (
            $png === false
            || $png === ''
        ) {
            throw new OcrProcessingException(
                'Preprocessed image could not be encoded as PNG.',
            );
        }

        return $png;
    }

    /**
     * Pipeline-stage log line.
     */
    private function log(
        string $bytes,
        ?PreprocessResult $result,
        string $outcome,
        array $extra = [],
    ): void {
        $context = array_merge(
            [
                'pipeline_stage' => 'preprocess',
                'image_hash' => substr(
                    hash('sha256', $bytes),
                    0,
                    12,
                ),
                'outcome' => $outcome,
            ],
            $extra,
        );

        if ($result !== null) {
            $context = array_merge(
                $context,
                [
                    'duration_ms' => $result->durationMs,
                    'width' => $result->width,
                    'height' => $result->height,
                    'mean_brightness' => $result->meanBrightness,
                    'transforms' => implode(
                        ',',
                        $result->appliedTransforms,
                    ),
                    'warnings' => implode(
                        ',',
                        $result->warnings,
                    ),
                ],
            );
        }

        Log::info(
            'OCR preprocessing '.$outcome,
            $context,
        );
    }
}
