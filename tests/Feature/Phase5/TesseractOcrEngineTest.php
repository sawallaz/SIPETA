<?php

namespace Tests\Feature\Phase5;

use App\Exceptions\OcrEngineException;
use App\Services\OcrResult;
use App\Services\TesseractOcrEngine;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Phase 5.4 — Tesseract OCR engine integration layer.
 *
 * Proves the TesseractOcrEngine builds the configured invocation (binary,
 * -l ind, --psm 6, tsv, stdout, timeout), parses TSV output into raw text +
 * mean word confidence, and surfaces engine failures as OcrEngineException.
 *
 * The real binary is never required: Process::fake() supplies results. One
 * optional test exercises the real Tesseract 5 binary and is skipped unless
 * RUN_TESSERACT_TESTS=1 (same gating as the Phase 3 real-MySQL test).
 */
class TesseractOcrEngineTest extends TestCase
{
    /** Tesseract 5 TSV: header + two lines of confident words. */
    private const SAMPLE_TSV = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n"
        ."1\t0\t0\t0\t0\t0\t0\t0\t800\t600\t-1\t\n"
        ."2\t0\t0\t0\t0\t0\t0\t0\t800\t600\t-1\t\n"
        ."3\t0\t0\t0\t0\t0\t45\t30\t700\t200\t-1\t\n"
        ."4\t0\t0\t0\t1\t0\t45\t30\t700\t50\t-1\t\n"
        ."5\t0\t0\t0\t1\t1\t45\t30\t120\t40\t95\t3207122801160001\n"
        ."5\t0\t0\t0\t1\t2\t180\t30\t200\t40\t90\tBUDI\n"
        ."4\t0\t0\t0\t2\t0\t45\t90\t300\t50\t-1\t\n"
        ."5\t0\t0\t0\t2\t1\t45\t90\t300\t40\t80\tKEPALA\n"
        ."5\t0\t0\t0\t2\t2\t360\t90\t150\t40\t85\tKELUARGA\n";

    public function test_successful_run_parses_tsv_into_raw_text_and_confidence(): void
    {
        Process::fake(['*' => Process::result(self::SAMPLE_TSV)]);

        $result = (new TesseractOcrEngine)->run('/tmp/sipeta/abc-preprocessed.png');

        $this->assertInstanceOf(OcrResult::class, $result);
        $this->assertSame("3207122801160001 BUDI\nKEPALA KELUARGA", $result->rawText);
        $this->assertSame(87.5, $result->confidence);
        $this->assertSame(4, $result->wordCount);
        $this->assertGreaterThanOrEqual(0, $result->durationMs);
    }

    public function test_run_invokes_tesseract_with_configured_options_and_timeout(): void
    {
        config([
            'ocr.tesseract_path' => '/opt/tesseract-ocr/tesseract',
            'ocr.language' => 'ind',
            'ocr.psm' => '6',
            'ocr.timeout_seconds' => 7,
        ]);

        Process::fake(['*' => Process::result(self::SAMPLE_TSV)]);

        (new TesseractOcrEngine)->run('/tmp/sipeta/abc-preprocessed.png');

        Process::assertRan(function ($process): bool {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($command, '/opt/tesseract-ocr/tesseract')
                && str_contains($command, 'stdout')
                && str_contains($command, '-l ind')
                && str_contains($command, '--psm 6')
                && str_contains($command, 'tsv')
                && str_contains($command, '/tmp/sipeta/abc-preprocessed.png')
                && $process->timeout === 7;
        });
    }

    public function test_empty_output_yields_empty_result(): void
    {
        Process::fake(['*' => Process::result('')]);

        $result = (new TesseractOcrEngine)->run('/tmp/sipeta/empty-preprocessed.png');

        $this->assertSame('', $result->rawText);
        $this->assertSame(0.0, $result->confidence);
        $this->assertSame(0, $result->wordCount);
    }

    public function test_nonzero_exit_throws_with_stderr(): void
    {
        Process::fake(['*' => Process::result('', 'Error opening data file /usr/share/tesseract-ocr/tessdata/ind.traineddata', 1)]);

        try {
            (new TesseractOcrEngine)->run('/tmp/sipeta/bad-preprocessed.png');
            $this->fail('Expected OcrEngineException for a failing tesseract run.');
        } catch (OcrEngineException $e) {
            $this->assertStringContainsString('Tesseract failed', $e->getMessage());
            $this->assertStringContainsString('ind.traineddata', $e->getMessage());
        }
    }

    public function test_nonzero_exit_without_stderr_throws_with_exit_code(): void
    {
        Process::fake(['*' => Process::result('', '', 2)]);

        try {
            (new TesseractOcrEngine)->run('/tmp/sipeta/bad-preprocessed.png');
            $this->fail('Expected OcrEngineException for a non-zero exit code.');
        } catch (OcrEngineException $e) {
            $this->assertStringContainsString('exited with code 2', $e->getMessage());
        }
    }

    public function test_real_tesseract_binary_invocation(): void
    {
        if (! env('RUN_TESSERACT_TESTS')) {
            $this->markTestSkipped('RUN_TESSERACT_TESTS not set; skipping real-Tesseract verification.');
        }

        $image = imagecreatetruecolor(1200, 400);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        imagettftext(
            $image,
            48,
            0,
            50,
            250,
            imagecolorallocate($image, 0, 0, 0),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            'NIK 3207122801160001'
        );

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        $path = tempnam(sys_get_temp_dir(), 'sipeta-ocr').'.png';
        file_put_contents($path, $png);

        try {
            $result = (new TesseractOcrEngine)->run($path);

            $this->assertInstanceOf(OcrResult::class, $result);
            $this->assertGreaterThanOrEqual(0, $result->durationMs);
            $this->assertStringContainsString('3207122801160001', $result->rawText);
        } finally {
            @unlink($path);
        }
    }
}
