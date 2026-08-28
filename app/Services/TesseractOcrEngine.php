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
 * output so word-level confidence and 2D spatial bounding boxes are available.
 */
class TesseractOcrEngine implements OcrEngine
{
    /** Tesseract TSV column indexes (level, page, block, par, line, word, left, top, width, height, conf, text). */
    private const COL_LEVEL = 0;
    private const COL_PAGE = 1;
    private const COL_BLOCK = 2;
    private const COL_PAR = 3;
    private const COL_LINE = 4;
    private const COL_WORD = 5;
    private const COL_LEFT = 6;
    private const COL_TOP = 7;
    private const COL_WIDTH = 8;
    private const COL_HEIGHT = 9;
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

        if (is_file($bin)) {
            $binDir = dirname($bin);
            $currentPath = (string) (getenv('PATH') ?: '');
            $env['PATH'] = $binDir.PATH_SEPARATOR.$currentPath;
        }

        $workDir = is_file($bin) ? dirname($bin) : base_path();
        $timeout = (int) (config('ocr.timeout_seconds') ?: 30);

        try {
            $process = Process::timeout($timeout)
                ->path($workDir)
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
        $primaryScore = $this->scoreQuality($fullResult);

        // Fallback terukur: jika PSM utama menghasilkan NIK/data minim (<2 NIK valid atau skor < 250), evaluasi candidate PSM
        $bestResult = $fullResult;
        $bestScore = $primaryScore;

        if ($primaryScore < 250.0 || $fullResult->wordCount < 15) {
            $candidatePsms = ['3', '4', '6', '11'];

            foreach ($candidatePsms as $candPsm) {
                if ($candPsm === (string) config('ocr.psm', '4')) {
                    continue;
                }
                $fallbackCmd = $this->command($imagePath, $bin, $tessdata, $candPsm);
                try {
                    $fallbackProc = Process::timeout($timeout)
                        ->path($workDir)
                        ->env($env)
                        ->run($fallbackCmd);
                    if ($fallbackProc->successful()) {
                        $fallbackRes = $this->parseOutput((string) $fallbackProc->output(), hrtime(true) - $started);
                        $fallbackScore = $this->scoreQuality($fallbackRes);
                        if ($fallbackScore > $bestScore) {
                            $bestResult = $fallbackRes;
                            $bestScore = $fallbackScore;
                        }
                    }
                } catch (\Throwable) {
                    // Abaikan error fallback dan lanjutkan kandidat lain
                }
            }
        }

        $imageDir = dirname($imagePath);
        $tableImagePath = $imageDir.DIRECTORY_SEPARATOR.'ocr-table-members.png';
        $bestTableResult = null;

        // Specialized Table OCR Pass jika gambar tabel anggota terdeteksi
        if (file_exists($tableImagePath)) {
            $tableCandidatePsms = ['3', '6', '4', '11'];
            $bestTableScore = -1.0;

            foreach ($tableCandidatePsms as $tPsm) {
                $tableCmd = $this->command($tableImagePath, $bin, $tessdata, $tPsm);
                try {
                    $tableProc = Process::timeout($timeout)
                        ->path($workDir)
                        ->env($env)
                        ->run($tableCmd);
                    if ($tableProc->successful()) {
                        $tableRes = $this->parseOutput((string) $tableProc->output(), hrtime(true) - $started);
                        $tableScore = $this->scoreQuality($tableRes);
                        if ($tableScore > $bestTableScore) {
                            $bestTableScore = $tableScore;
                            $bestTableResult = $tableRes;
                        }
                    }
                } catch (\Throwable) {
                    // Abaikan kegagalan kandidat tabel
                }
            }
        }

        // Simpan debug artifacts pada temp directory jika memungkinkan
        if (str_contains($imageDir, 'ocr_temp') || str_contains($imageDir, 'tmp') || str_contains($imageDir, 'temp')) {
            @file_put_contents($imageDir.DIRECTORY_SEPARATOR.'ocr-full.tsv', (string) $bestResult->tsv);
            @file_put_contents($imageDir.DIRECTORY_SEPARATOR.'ocr-full.txt', $bestResult->rawText);
            @file_put_contents($imageDir.DIRECTORY_SEPARATOR.'ocr-raw.txt', $bestResult->rawText);

            if ($bestTableResult !== null) {
                @file_put_contents($imageDir.DIRECTORY_SEPARATOR.'ocr-table.tsv', (string) $bestTableResult->tsv);
                @file_put_contents($imageDir.DIRECTORY_SEPARATOR.'ocr-table.txt', $bestTableResult->rawText);
                @file_put_contents($imageDir.DIRECTORY_SEPARATOR.'ocr-table-raw.txt', $bestTableResult->rawText);
            }
        }

        return new OcrResult(
            rawText: $bestResult->rawText,
            confidence: $bestResult->confidence,
            wordCount: $bestResult->wordCount,
            durationMs: $bestResult->durationMs,
            tableRawText: $bestTableResult?->rawText,
            tsv: $bestResult->tsv,
            tokens: $bestResult->tokens,
            tableTsv: $bestTableResult?->tsv,
            tableTokens: $bestTableResult?->tokens,
        );
    }

    /**
     * Hitung skor kualitas hasil OCR berdasarkan:
     * 1. Jumlah NIK 16 digit valid (bobot tertinggi)
     * 2. Kelengkapan kata kunci struktur KK (header/tabel/kolom)
     * 3. Rata-rata confidence kata
     * 4. Kepadatan kata yang terbaca
     */
    public function scoreQuality(OcrResult $res): float
    {
        if ($res->wordCount === 0 || trim($res->rawText) === '') {
            return 0.0;
        }

        $text = mb_strtoupper($res->rawText);
        $score = 0.0;

        // 1. Valid 16-digit NIK count (bobot utama)
        preg_match_all('/\b\d{16}\b/', $res->rawText, $nikMatches);
        $validNikCount = count(array_unique($nikMatches[0] ?? []));
        $score += $validNikCount * 80.0;

        // 1b. Spaced NIK count
        preg_match_all('/\b\d{3,8}\s+\d{8,13}\b/', $res->rawText, $spacedNiks);
        foreach ($spacedNiks[0] ?? [] as $sn) {
            $digits = preg_replace('/\D/', '', $sn);
            if (strlen($digits) === 16) {
                $score += 70.0;
            }
        }

        // 2. Keyword detection struktur KK
        $keywords = [
            'KARTU KELUARGA', 'NOMOR', 'NIK', 'NAMA', 'ALAMAT', 'RT/RW',
            'JENIS KELAMIN', 'TEMPAT LAHIR', 'TANGGAL LAHIR', 'AGAMA',
            'PENDIDIKAN', 'PEKERJAAN', 'STATUS PERKAWINAN', 'HUBUNGAN',
            'KEPALA KELUARGA', 'ISTRI', 'ANAK', 'LAKI-LAKI', 'PEREMPUAN',
            'ISLAM', 'KRISTEN', 'KATOLIK', 'KAWIN', 'BELUM KAWIN',
        ];
        foreach ($keywords as $kw) {
            if (str_contains($text, $kw)) {
                $score += 10.0;
            }
        }

        // 3. Word confidence & count contribution
        $score += ($res->confidence * 0.5);
        $score += min(30.0, $res->wordCount * 0.3);

        return $score;
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

        if (PHP_OS_FAMILY === 'Windows') {
            $bundledWin = base_path('resources/tesseract/tesseract.exe');
            if (file_exists($bundledWin)) {
                return $bundledWin;
            }
        } else {
            $bundledLinux = base_path('resources/tesseract/tesseract');
            if (file_exists($bundledLinux) && is_executable($bundledLinux)) {
                return $bundledLinux;
            }
        }

        return 'tesseract';
    }

    /**
     * Resolve TESSDATA_PREFIX with bundled fallback.
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
     * Build the tesseract invocation as an argument array.
     *
     * @return array<int, string>
     */
    private function command(string $imagePath, ?string $bin = null, ?string $tessdata = null, ?string $psm = null): array
    {
        $cmd = [
            $bin ?: (string) config('ocr.tesseract_path'),
            $imagePath,
            'stdout',
            '-l',
            (string) config('ocr.language'),
            '--psm',
            $psm ?: (string) config('ocr.psm'),
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
     * Parse Tesseract TSV output into raw text + mean word confidence + full 2D token array.
     */
    private function parseOutput(string $tsv, int $elapsedNanoseconds): OcrResult
    {
        /** @var array<string, array<int, string>> $lineGroups reading-order line buckets */
        $lineGroups = [];
        $confidences = [];
        $tokens = [];

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

            $left = (int) $columns[self::COL_LEFT];
            $top = (int) $columns[self::COL_TOP];
            $width = (int) $columns[self::COL_WIDTH];
            $height = (int) $columns[self::COL_HEIGHT];

            $confidences[] = $confidence;
            $tokens[] = [
                'text' => $text,
                'conf' => $confidence,
                'left' => $left,
                'top' => $top,
                'width' => $width,
                'height' => $height,
                'cx' => $left + ($width / 2),
                'cy' => $top + ($height / 2),
                'page_num' => (int) $columns[self::COL_PAGE],
                'block_num' => (int) $columns[self::COL_BLOCK],
                'par_num' => (int) $columns[self::COL_PAR],
                'line_num' => (int) $columns[self::COL_LINE],
                'word_num' => (int) $columns[self::COL_WORD],
            ];

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
            tableRawText: null,
            tsv: $tsv,
            tokens: $tokens,
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
