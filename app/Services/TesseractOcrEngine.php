<?php

namespace App\Services;

use App\Exceptions\OcrEngineException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
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

        try {
            $process = Process::timeout((int) config('ocr.timeout_seconds'))->run($this->command($imagePath));
        } catch (ProcessTimedOutException) {
            throw new OcrEngineException($this->timeoutMessage());
        }

        if (! $process->successful()) {
            throw new OcrEngineException($this->failureMessage($process));
        }

        return $this->parseOutput((string) $process->output(), hrtime(true) - $started);
    }

    /**
     * Build the tesseract invocation as an argument array (Symfony Process
     * handles quoting for paths with spaces).
     *
     * @return array<int, string>
     */
    private function command(string $imagePath): array
    {
        return [
            (string) config('ocr.tesseract_path'),
            $imagePath,
            'stdout',
            '-l',
            (string) config('ocr.language'),
            '--psm',
            (string) config('ocr.psm'),
            'tsv',
        ];
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
