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

        // Simpan debug original image
        @$this->filesystem->disk(self::DISK)->put('ocr-original.png', $bytes);

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
         * 3b. LIGHT MARGIN AUTO-TRIM (Document Boundary Detection)
         * ============================================================
         */
        $boundaries = $this->detectDocumentBoundaries($image);
        if ($boundaries !== null) {
            $cropped = imagecreatetruecolor($boundaries['w'], $boundaries['h']);
            if ($cropped !== false) {
                imagecopy(
                    $cropped,
                    $image,
                    0, 0,
                    $boundaries['x'], $boundaries['y'],
                    $boundaries['w'], $boundaries['h']
                );
                imagedestroy($image);
                $image = $cropped;
                $width = $boundaries['w'];
                $height = $boundaries['h'];
                $transforms[] = 'margin_trim';
            }
        }
        $croppedPng = $this->encodePng($image);
        @$this->filesystem->disk(self::DISK)->put('ocr-cropped.png', $croppedPng);

        /*
         * ============================================================
         * 3c. DESKEW / ROTATION CORRECTION
         * ============================================================
         */
        $detectedSkew = $this->detectSkewAngle($image);
        if (abs($detectedSkew) >= 0.5) {
            $deskewed = $this->applyDeskew($image, $detectedSkew);
            if ($deskewed !== null) {
                imagedestroy($image);
                $image = $deskewed;
                $width = imagesx($image);
                $height = imagesy($image);
                $transforms[] = 'deskew_'.round($detectedSkew, 1).'deg';
            }
        }
        $deskewedPng = $this->encodePng($image);
        @$this->filesystem->disk(self::DISK)->put('ocr-deskewed.png', $deskewedPng);

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

        // Simpan debug table crop (Tabel Anggota) secara spesifik
        $tableBounds = $this->detectMemberTableBoundaries($image);
        if ($tableBounds !== null) {
            $tableCrop = imagecreatetruecolor($tableBounds['w'], $tableBounds['h']);
            if ($tableCrop !== false) {
                imagecopy(
                    $tableCrop,
                    $image,
                    0, 0,
                    $tableBounds['x'], $tableBounds['y'],
                    $tableBounds['w'], $tableBounds['h']
                );
                $tablePng = $this->encodePng($tableCrop);
                @$this->filesystem->disk(self::DISK)->put('ocr-table-members.png', $tablePng);
                @$this->filesystem->disk(self::DISK)->put('ocr-table-processed.png', $tablePng);
                imagedestroy($tableCrop);
            }
        }

        $outputName = pathinfo(
            $sourcePath,
            PATHINFO_FILENAME,
        ).'-preprocessed.png';

        $png = $this->encodePng($image);

        // Simpan debug artifacts
        @$this->filesystem->disk(self::DISK)->put('ocr-preprocessed.png', $png);
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

    /**
     * Deteksi batas dokumen secara cerdas dan konservatif.
     * Hanya membuang margin luar (background meja / border hitam kamera)
     * tanpa pernah memotong bagian header, tabel, kolom, atau tanda tangan.
     *
     * @return array{x: int, y: int, w: int, h: int}|null
     */
    private function detectDocumentBoundaries(\GdImage $image): ?array
    {
        $w = imagesx($image);
        $h = imagesy($image);

        if ($w < 800 || $h < 600) {
            return null;
        }

        // Buat thumbnail kecil untuk profiling cepat
        $thumbW = 400;
        $thumbH = (int) round($h * ($thumbW / $w));
        $thumb = imagecreatetruecolor($thumbW, $thumbH);
        if ($thumb === false) {
            return null;
        }

        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $thumbW, $thumbH, $w, $h);
        imagefilter($thumb, IMG_FILTER_GRAYSCALE);

        // Ambil sampel luminance di 4 sudut luar (masing-masing 15x15 px)
        $cornerSamples = [];
        for ($cy = 0; $cy < 15; $cy++) {
            for ($cx = 0; $cx < 15; $cx++) {
                $cornerSamples[] = (imagecolorat($thumb, $cx, $cy) & 0xFF);
                $cornerSamples[] = (imagecolorat($thumb, $thumbW - 1 - $cx, $cy) & 0xFF);
                $cornerSamples[] = (imagecolorat($thumb, $cx, $thumbH - 1 - $cy) & 0xFF);
                $cornerSamples[] = (imagecolorat($thumb, $thumbW - 1 - $cx, $thumbH - 1 - $cy) & 0xFF);
            }
        }
        sort($cornerSamples);
        $bgLuminance = $cornerSamples[(int) (count($cornerSamples) * 0.5)];

        // Jika sudut sudah terang (kertas dokumen memenuhi seluruh frame / scan bersih), tidak perlu crop
        if ($bgLuminance >= 140) {
            imagedestroy($thumb);

            return null;
        }

        $threshold = max(70, $bgLuminance + 30);

        // Cari batas atas & bawah
        $minY = 0;
        $maxY = $thumbH - 1;
        for ($y = 0; $y < $thumbH; $y++) {
            $rowBright = 0;
            for ($x = (int) ($thumbW * 0.2); $x < (int) ($thumbW * 0.8); $x++) {
                if ((imagecolorat($thumb, $x, $y) & 0xFF) > $threshold) {
                    $rowBright++;
                }
            }
            if ($rowBright > ($thumbW * 0.6 * 0.35)) {
                $minY = $y;
                break;
            }
        }

        for ($y = $thumbH - 1; $y >= $minY; $y--) {
            $rowBright = 0;
            for ($x = (int) ($thumbW * 0.2); $x < (int) ($thumbW * 0.8); $x++) {
                if ((imagecolorat($thumb, $x, $y) & 0xFF) > $threshold) {
                    $rowBright++;
                }
            }
            if ($rowBright > ($thumbW * 0.6 * 0.35)) {
                $maxY = $y;
                break;
            }
        }

        // Cari batas kiri & kanan
        $minX = 0;
        $maxX = $thumbW - 1;
        for ($x = 0; $x < $thumbW; $x++) {
            $colBright = 0;
            for ($y = (int) ($thumbH * 0.2); $y < (int) ($thumbH * 0.8); $y++) {
                if ((imagecolorat($thumb, $x, $y) & 0xFF) > $threshold) {
                    $colBright++;
                }
            }
            if ($colBright > ($thumbH * 0.6 * 0.35)) {
                $minX = $x;
                break;
            }
        }

        for ($x = $thumbW - 1; $x >= $minX; $x--) {
            $colBright = 0;
            for ($y = (int) ($thumbH * 0.2); $y < (int) ($thumbH * 0.8); $y++) {
                if ((imagecolorat($thumb, $x, $y) & 0xFF) > $threshold) {
                    $colBright++;
                }
            }
            if ($colBright > ($thumbH * 0.6 * 0.35)) {
                $maxX = $x;
                break;
            }
        }

        imagedestroy($thumb);

        $scaleX = $w / $thumbW;
        $scaleY = $h / $thumbH;

        $origMinX = (int) floor($minX * $scaleX);
        $origMaxX = (int) ceil($maxX * $scaleX);
        $origMinY = (int) floor($minY * $scaleY);
        $origMaxY = (int) ceil($maxY * $scaleY);

        // Berikan safety padding konservatif (2%) keluar agar garis dokumen tidak pernah tersentuh
        $padX = (int) round($w * 0.02);
        $padY = (int) round($h * 0.02);

        $finalMinX = max(0, $origMinX - $padX);
        $finalMaxX = min($w - 1, $origMaxX + $padX);
        $finalMinY = max(0, $origMinY - $padY);
        $finalMaxY = min($h - 1, $origMaxY + $padY);

        $cropW = $finalMaxX - $finalMinX + 1;
        $cropH = $finalMaxY - $finalMinY + 1;

        // Guard: jika area dokumen terdeteksi sudah mencakup >= 93% canvas, tidak perlu crop
        if ($cropW >= $w * 0.93 && $cropH >= $h * 0.93) {
            return null;
        }

        // Guard: jika area dokumen terdeteksi < 50% canvas (terlalu kecil atau anomali), jangan crop
        if ($cropW < $w * 0.50 || $cropH < $h * 0.50) {
            return null;
        }

        return [
            'x' => $finalMinX,
            'y' => $finalMinY,
            'w' => $cropW,
            'h' => $cropH,
        ];
    }

    /**
     * Deteksi sudut rotasi/kemiringan dokumen (deskew) berbasis variansi proyeksi horizontal.
     * Menguji sudut dari -4.0° hingga +4.0° dengan interval 0.5°.
     */
    public function detectSkewAngle(\GdImage $image): float
    {
        $w = imagesx($image);
        $h = imagesy($image);

        if ($w < 600 || $h < 400) {
            return 0.0;
        }

        $size = 300;
        $centerSquare = imagecreatetruecolor($size, $size);
        if ($centerSquare === false) {
            return 0.0;
        }

        $srcX = (int) (($w - $h * 0.7) / 2);
        $srcY = (int) ($h * 0.15);
        $srcSize = (int) ($h * 0.7);
        imagecopyresampled($centerSquare, $image, 0, 0, $srcX, $srcY, $size, $size, $srcSize, $srcSize);
        imagefilter($centerSquare, IMG_FILTER_GRAYSCALE);

        $bestAngle = 0.0;
        $maxVariance = 0.0;
        $baseVariance = 0.0;

        for ($angle = -4.0; $angle <= 4.0; $angle += 0.5) {
            $white = imagecolorallocate($centerSquare, 255, 255, 255);
            $rotated = $angle === 0.0 ? $centerSquare : imagerotate($centerSquare, $angle, $white);
            if ($rotated === false) {
                continue;
            }

            $rw = imagesx($rotated);
            $rh = imagesy($rotated);

            // Sample secara stabil inner 200x200 square
            $cropX = (int) (($rw - 200) / 2);
            $cropY = (int) (($rh - 200) / 2);

            $rowSums = [];
            $totalSum = 0.0;
            $count = 0;

            for ($y = $cropY; $y < $cropY + 200; $y += 2) {
                $sum = 0;
                for ($x = $cropX; $x < $cropX + 200; $x += 2) {
                    $val = imagecolorat($rotated, $x, $y) & 0xFF;
                    if ($val < 120) {
                        $sum++;
                    }
                }
                $rowSums[] = $sum;
                $totalSum += $sum;
                $count++;
            }

            if ($angle !== 0.0) {
                imagedestroy($rotated);
            }

            if ($count === 0) {
                continue;
            }

            $mean = $totalSum / $count;
            $variance = 0.0;
            foreach ($rowSums as $rs) {
                $variance += ($rs - $mean) * ($rs - $mean);
            }

            if ($angle === 0.0) {
                $baseVariance = $variance;
            }

            if ($variance > $maxVariance) {
                $maxVariance = $variance;
                $bestAngle = $angle;
            }
        }

        imagedestroy($centerSquare);

        // Hanya terapkan rotasi jika kenaikan variansi signifikan (> 10%)
        if (abs($bestAngle) >= 0.5 && $baseVariance > 0 && ($maxVariance / $baseVariance) > 1.10) {
            return $bestAngle;
        }

        return 0.0;
    }

    /**
     * Terapkan rotasi deskew pada gambar dokumen.
     */
    public function applyDeskew(\GdImage $image, float $angle): ?\GdImage
    {
        $white = imagecolorallocate($image, 255, 255, 255);
        $rotated = imagerotate($image, -$angle, $white);

        return $rotated !== false ? $rotated : null;
    }

    /**
     * Deteksi bounding box tabel anggota (Tabel 1) secara otomatis berbasis kepadatan garis horizontal.
     *
     * @return array{x: int, y: int, w: int, h: int}|null
     */
    public function detectMemberTableBoundaries(\GdImage $image): ?array
    {
        $w = imagesx($image);
        $h = imagesy($image);

        if ($w < 600 || $h < 400) {
            return null;
        }

        $tw = 600;
        $th = (int) round($h * ($tw / $w));
        $thumb = imagecreatetruecolor($tw, $th);
        if ($thumb === false) {
            return null;
        }

        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $tw, $th, $w, $h);

        // Ukur densitas pixel gelap per baris
        $rowDensity = [];
        for ($y = 0; $y < $th; $y++) {
            $dark = 0;
            for ($x = (int) ($tw * 0.05); $x < (int) ($tw * 0.95); $x++) {
                $val = imagecolorat($thumb, $x, $y) & 0xFF;
                if ($val < 110) {
                    $dark++;
                }
            }
            $rowDensity[$y] = $dark;
        }

        imagedestroy($thumb);

        $threshold = $tw * 0.9 * 0.25;
        $tableTopY = null;
        $tableBottomY = null;

        for ($y = (int) ($th * 0.18); $y <= (int) ($th * 0.40); $y++) {
            if ($rowDensity[$y] > $threshold) {
                $tableTopY = $y;
                break;
            }
        }

        for ($y = (int) ($th * 0.42); $y <= (int) ($th * 0.70); $y++) {
            if ($rowDensity[$y] > $threshold) {
                $tableBottomY = $y;
                break;
            }
        }

        $scaleY = $h / $th;

        if ($tableTopY !== null && $tableBottomY !== null && $tableBottomY > $tableTopY) {
            $origY1 = (int) floor($tableTopY * $scaleY);
            $origY2 = (int) ceil($tableBottomY * $scaleY);
        } else {
            // Proporsional fallback aman (20% s/d 50%)
            $origY1 = (int) round($h * 0.20);
            $origY2 = (int) round($h * 0.50);
        }

        // Safety padding 1.5%
        $padY = (int) round($h * 0.015);
        $finalY1 = max(0, $origY1 - $padY);
        $finalY2 = min($h - 1, $origY2 + $padY);
        $finalH = $finalY2 - $finalY1 + 1;

        return [
            'x' => 0,
            'y' => $finalY1,
            'w' => $w,
            'h' => $finalH,
        ];
    }
}
