<?php

namespace App\Services;

use App\Exceptions\OcrEngineException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Tesseract OCR engine (Phase 5.4, .ai/ocr.md §4.3).
 *
 * Invokes the Tesseract binary via Laravel's Process facade:
 *
 *   tesseract <image> stdout -l ind --psm 6 tsv
 *
 * — Indonesian language pack, single uniform text block (PSM 6), and TSV
 * output so word-level confidence is available. TSV word rows (level 5) are
 * grouped back into lines in reading order; the raw text is the joined
 * lines and the confidence is the mean of the word confidences.
 *
 * The binary path, language, PSM, and timeout come from config/ocr.php
 * (.ai/ocr.md §6). Failures (non-zero exit, timeout) raise
 * OcrEngineException; the pipeline persists the job as FAILED.
 *
 * Engine tuning not yet applied (documented in docs/PHASE5.md §5.4.3): the
 * character whitelist from .ai/ocr.md §4.3 is deferred — a digits/uppercase
 * whitelist would mangle lowercase address and name text before the parsing
 * stage exists to handle casing.
 */
class TesseractOcrEngine implements OcrEngine
{
    /** Tesseract TSV column indexes (level, page, block, par, line, word, ...). */
    private const COL_LEVEL = 0;

    private const COL_PAGE = 1;

    private const COL_BLOCK = 2;

    private const COL_PAR = 3;

    private const COL_LINE = 4;

    private const COL_CONF = 10;

    private const COL_TEXT = 11;

    /** TSV level of word rows (5 = word). */
    private const LEVEL_WORD = 5;

    /** Words with confidence < 0 carry no usable confidence (Tesseract uses -1). */
    private const CONFIDENCE_NONE = 0;

    public function run(string $imagePath): OcrResult
    {
        $started = hrtime(true);
        $bin = $this->resolveTesseractBinary();
        $tessdata = $this->resolveTessdataPrefix();
        $cmd = $this->command($imagePath, $bin, $tessdata);
        $env = filled($tessdata) ? ['TESSDATA_PREFIX' => (string) $tessdata] : [];

        try {
            $process = Process::timeout((int) config('ocr.timeout_seconds'))
                ->env($env)
                ->run($cmd);
        } catch (ProcessTimedOutException) {
            throw new OcrEngineException($this->timeoutMessage());
        }

        if (! $process->successful()) {
            Log::warning('Tesseract OCR execution failed', [
                'command' => $cmd,
                'tesseract_bin' => $bin,
                'bin_exists' => file_exists($bin),
                'tessdata_prefix' => $tessdata,
                'tessdata_exists' => filled($tessdata) && is_dir((string) $tessdata),
                'ind_traineddata_exists' => filled($tessdata) && file_exists(((string) $tessdata).DIRECTORY_SEPARATOR.'ind.traineddata'),
                'exit_code' => $process->exitCode(),
                'stderr' => trim((string) $process->errorOutput()),
                'stdout_snippet' => substr(trim((string) $process->output()), 0, 300),
                'image_path' => $imagePath,
                'image_exists' => file_exists($imagePath),
                'image_size' => file_exists($imagePath) ? filesize($imagePath) : 0,
            ]);

            throw new OcrEngineException($this->failureMessage($process));
        }

        $fullResult = $this->parseOutput((string) $process->output(), hrtime(true) - $started);

        // Multi-zone Region of Interest (ROI) scanning untuk akurasi tinggi pada dokumen Kartu Keluarga
        $zonedText = $this->runMultiZoneOcr($imagePath, $bin, $tessdata, $env);
        if ($zonedText !== null && strlen($zonedText) > 20) {
            $mergedText = $zonedText . "\n\n" . $fullResult->rawText;
            return new OcrResult(
                rawText: $mergedText,
                confidence: $fullResult->confidence,
                wordCount: $fullResult->wordCount,
                durationMs: round((hrtime(true) - $started) / 1_000_000, 2),
            );
        }

        return $fullResult;
    }

    /**
     * Jalankan OCR tersegmentasi per Region of Interest (ROI):
     * - Zona Header (0% - 28% tinggi gambar)
     * - Zona Tabel I (24% - 65% tinggi gambar)
     * - Zona Tabel II (60% - 95% tinggi gambar)
     */
    private function runMultiZoneOcr(string $imagePath, string $bin, ?string $tessdata, array $env): ?string
    {
        if (! file_exists($imagePath) || ! extension_loaded('gd')) {
            return null;
        }

        $image = @imagecreatefrompng($imagePath)
            ?: @imagecreatefromjpeg($imagePath)
            ?: @imagecreatefromstring((string) @file_get_contents($imagePath));

        if (! $image) {
            return null;
        }

        $w = imagesx($image);
        $h = imagesy($image);

        if ($w < 500 || $h < 500) {
            imagedestroy($image);
            return null;
        }

        $zones = [
            'header' => ['x' => 0, 'y' => 0, 'width' => $w, 'height' => (int) ($h * 0.28)],
            'table1' => ['x' => 0, 'y' => (int) ($h * 0.24), 'width' => $w, 'height' => (int) ($h * 0.42)],
            'table2' => ['x' => 0, 'y' => (int) ($h * 0.60), 'width' => $w, 'height' => (int) ($h * 0.38)],
        ];

        $zoneTexts = [];

        foreach ($zones as $key => $rect) {
            $cropped = imagecrop($image, $rect);
            if (! $cropped) {
                continue;
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'sipeta_zone_'.$key.'_').'.png';
            imagepng($cropped, $tempFile);
            imagedestroy($cropped);

            $cmd = $this->command($tempFile, $bin, $tessdata);
            try {
                $process = Process::timeout((int) config('ocr.timeout_seconds'))
                    ->env($env)
                    ->run($cmd);
                if ($process->successful()) {
                    $zoneRes = $this->parseOutput((string) $process->output(), 0);
                    if (trim($zoneRes->rawText) !== '') {
                        $zoneTexts[$key] = trim($zoneRes->rawText);
                    }
                }
            } catch (\Throwable) {
                // Abaikan kegagalan zona individu
            } finally {
                @unlink($tempFile);
            }
        }

        imagedestroy($image);

        if (count($zoneTexts) >= 2) {
            return implode("\n\n", $zoneTexts);
        }

        return null;
    }

    /**
     * Resolve Tesseract binary with bundled fallback.
     */
    public function resolveTesseractBinary(): string
    {
        $configured = (string) config('ocr.tesseract_path', 'tesseract');

        if ($configured !== '' && $configured !== 'tesseract') {
            return $configured;
        }

        $bundledWin = base_path('resources/tesseract/tesseract.exe');
        if (file_exists($bundledWin)) {
            return $bundledWin;
        }

        $bundledLinux = base_path('resources/tesseract/tesseract');
        if (file_exists($bundledLinux)) {
            return $bundledLinux;
        }

        return $configured;
    }

    /**
     * Resolve Tessdata directory with bundled fallback.
     */
    public function resolveTessdataPrefix(): ?string
    {
        $configured = config('ocr.tessdata_prefix') ?: env('TESSDATA_PREFIX');

        if (filled($configured) && is_dir((string) $configured)) {
            return (string) $configured;
        }

        $bundled = base_path('resources/tesseract/tessdata');
        if (is_dir($bundled)) {
            return $bundled;
        }

        return filled($configured) ? (string) $configured : null;
    }

    /**
     * Build the tesseract invocation as an argument array (Symfony Process
     * handles quoting for paths with spaces).
     *
     * @return array<int, string>
     */
    private function command(string $imagePath, ?string $bin = null, ?string $tessdata = null): array
    {
        $cmd = [
            $bin ?: (string) config('ocr.tesseract_path'),
            $imagePath,
            'stdout',
            '-l',
            (string) config('ocr.language'),
            '--psm',
            (string) config('ocr.psm'),
        ];

        $tessdata = $tessdata ?: (config('ocr.tessdata_prefix') ?: env('TESSDATA_PREFIX'));
        if (filled($tessdata)) {
            $cmd[] = '--tessdata-dir';
            $cmd[] = (string) $tessdata;
        }

        $cmd[] = 'tsv';

        return $cmd;
    }

    /**
     * Parse Tesseract TSV output into raw text + mean word confidence.
     */
    private function parseOutput(string $tsv, int $elapsedNanoseconds): OcrResult
    {
        /** @var array<string, array<int, string>> $lineGroups reading-order line buckets */
        $lineGroups = [];
        $confidences = [];

        foreach (explode("\n", $tsv) as $row) {
            $columns = explode("\t", $row);

            if (count($columns) <= self::COL_TEXT || (int) $columns[self::COL_LEVEL] !== self::LEVEL_WORD) {
                continue;
            }

            $confidence = (float) $columns[self::COL_CONF];
            $text = trim($columns[self::COL_TEXT]);

            if ($confidence < self::CONFIDENCE_NONE || $text === '') {
                continue;
            }

            $confidences[] = $confidence;

            $lineKey = implode(':', [
                $columns[self::COL_PAGE],
                $columns[self::COL_BLOCK],
                $columns[self::COL_PAR],
                $columns[self::COL_LINE],
            ]);
            $lineGroups[$lineKey][] = $text;
        }

        $lines = array_map(fn (array $words): string => implode(' ', $words), $lineGroups);
        $rawText = implode("\n", $lines);

        $meanConfidence = $confidences === []
            ? 0.0
            : round(array_sum($confidences) / count($confidences), 2);

        return new OcrResult(
            rawText: $rawText,
            confidence: $meanConfidence,
            wordCount: count($confidences),
            durationMs: round($elapsedNanoseconds / 1_000_000, 2),
        );
    }

    private function timeoutMessage(): string
    {
        return sprintf('OCR timed out after %d seconds.', (int) config('ocr.timeout_seconds'));
    }

    /**
     * @param  ProcessResult  $process
     */
    private function failureMessage($process): string
    {
        $stderr = trim((string) $process->errorOutput());

        if ($stderr !== '') {
            return 'Tesseract failed: '.$stderr;
        }

        return sprintf('Tesseract exited with code %d.', (int) $process->exitCode());
    }
}
