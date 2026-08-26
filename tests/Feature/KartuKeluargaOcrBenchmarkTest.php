<?php

namespace Tests\Feature;

use App\Models\Education;
use App\Models\Occupation;
use App\Models\Religion;
use App\Services\OcrParsingService;
use App\Services\ParsedOcrResult;
use App\Services\TesseractOcrEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OCR Benchmark Test — Multi-Condition Stress Test
 *
 * Menjalankan pipeline OCR pada 3 variasi gambar KK:
 *   1. kk_clean_highres.png  — Resolusi jernih (baseline)
 *   2. kk_low_res.png        — Resolusi rendah ~1100px (validasi nomor KK utama)
 *   3. kk_camera_noise.png   — Foto HP dengan noise & tint (stress test)
 *
 * Target akurasi:
 *   - kk_clean_highres: ≥ 95% (ideal 100%)
 *   - kk_camera_noise:  ≥ 70% (toleransi noise + color cast + rotasi)
 *   - kk_low_res:       Validasi ekstraksi Nomor KK utama
 */
class KartuKeluargaOcrBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Ground Truth
    // -----------------------------------------------------------------------
    private const GROUND_TRUTH_KK_NUMBER = '7304012304990001';
    private const GROUND_TRUTH_ADDRESS   = 'JL. POROS PARE-PARE NO. 45';
    private const GROUND_TRUTH_RT        = '001';
    private const GROUND_TRUTH_RW        = '002';
    private const GROUND_TRUTH_MEMBERS   = [
        1 => [
            'nik'             => '7304010101750001',
            'nama'            => 'MUHAMMAD AMIN',
            'gender'          => 'LAKI_LAKI',
            'birth_place'     => 'BARRU',
            'birth_date'      => '1975-01-01',
            'religion'        => 'ISLAM',
            'education'       => 'SLTA/SEDERAJAT',
            'occupation'      => 'PETANI',
            'marital_status'  => 'KAWIN',
            'family_relation' => 'KEPALA_KELUARGA',
            'ayah'            => 'ABDULLAH',
            'ibu'             => 'FATIMAH',
        ],
        2 => [
            'nik'             => '7304014506800002',
            'nama'            => 'NURHAYATI',
            'gender'          => 'PEREMPUAN',
            'birth_place'     => 'BARRU',
            'birth_date'      => '1980-06-05',
            'religion'        => 'ISLAM',
            'education'       => 'SLTA/SEDERAJAT',
            'occupation'      => 'IBU RUMAH TANGGA',
            'marital_status'  => 'KAWIN',
            'family_relation' => 'ISTRI',
            'ayah'            => 'HASAN',
            'ibu'             => 'MARIAM',
        ],
        3 => [
            'nik'             => '7304011208050003',
            'nama'            => 'AHMAD FAUZI',
            'gender'          => 'LAKI_LAKI',
            'birth_place'     => 'BARRU',
            'birth_date'      => '2005-08-12',
            'religion'        => 'ISLAM',
            'education'       => 'SLTA/SEDERAJAT',
            'occupation'      => 'PELAJAR/MAHASISWA',
            'marital_status'  => 'BELUM_KAWIN',
            'family_relation' => 'ANAK',
            'ayah'            => 'MUHAMMAD AMIN',
            'ibu'             => 'NURHAYATI',
        ],
        4 => [
            'nik'             => '7304015011100004',
            'nama'            => 'SITIAISYAH',
            'gender'          => 'PEREMPUAN',
            'birth_place'     => 'BARRU',
            'birth_date'      => '2010-11-10',
            'religion'        => 'ISLAM',
            'education'       => 'SLTP/SEDERAJAT',
            'occupation'      => 'PELAJAR/MAHASISWA',
            'marital_status'  => 'BELUM_KAWIN',
            'family_relation' => 'ANAK',
            'ayah'            => 'MUHAMMAD AMIN',
            'ibu'             => 'NURHAYATI',
        ],
    ];

    // Member fields to check for accuracy
    private const MEMBER_FIELDS = [
        'nik', 'nama', 'gender', 'birth_place', 'birth_date',
        'religion', 'education', 'occupation', 'marital_status',
        'family_relation', 'ayah', 'ibu',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Religion::firstOrCreate(['name' => 'ISLAM']);
        Religion::firstOrCreate(['name' => 'KRISTEN']);
        Religion::firstOrCreate(['name' => 'KATOLIK']);
        Education::firstOrCreate(['name' => 'SLTA/SEDERAJAT']);
        Education::firstOrCreate(['name' => 'SLTP/SEDERAJAT']);
        Education::firstOrCreate(['name' => 'TAMAT SD/SEDERAJAT']);
        Occupation::firstOrCreate(['name' => 'PETANI']);
        Occupation::firstOrCreate(['name' => 'IBU RUMAH TANGGA']);
        Occupation::firstOrCreate(['name' => 'PELAJAR/MAHASISWA']);
    }

    // -----------------------------------------------------------------------
    // Test 1: Clean High-Res (Baseline) — must be ≥ 95%
    // -----------------------------------------------------------------------

    public function test_ocr_benchmark_clean_highres(): void
    {
        $this->runBenchmark('kk_clean_highres.png', 95.0);
    }

    // -----------------------------------------------------------------------
    // Test 2: Low Resolution — validates core Nomor KK extraction
    // -----------------------------------------------------------------------

    public function test_ocr_benchmark_low_res(): void
    {
        $fixturePath = base_path('tests/Fixtures/kk_low_res.png');

        if (! file_exists($fixturePath)) {
            $this->markTestSkipped('kk_low_res.png not generated yet. Run tests/Fixtures/generate_test_kk_variants.php first.');
        }

        $engine = app(TesseractOcrEngine::class);
        $parser = app(OcrParsingService::class);

        $ocrResult = $engine->run($fixturePath);
        $this->assertNotEmpty($ocrResult->rawText, 'OCR raw text must not be empty for kk_low_res.png');

        $parsed = $parser->parse($ocrResult->rawText, $ocrResult->confidence);
        $this->assertInstanceOf(ParsedOcrResult::class, $parsed);

        // Core extraction validation: Nomor KK must be extracted accurately despite low-res downscaling
        $this->assertSame(self::GROUND_TRUTH_KK_NUMBER, $parsed->kkNumber, 'Nomor KK must be extracted from low-res image');

        fwrite(STDOUT, PHP_EOL . sprintf(
            "┌─ BENCHMARK: %-30s ─────────────────────────┐\n│ Core Field      : Nomor KK = %s\n│ Expected        : %s\n│ Status          : %s\n└─────────────────────────────────────────────────────────────────────┘\n",
            'kk_low_res.png',
            $parsed->kkNumber ?? '(null)',
            self::GROUND_TRUTH_KK_NUMBER,
            $parsed->kkNumber === self::GROUND_TRUTH_KK_NUMBER ? '✅ PASS (Core KK Identified)' : '❌ FAIL',
        ));
    }

    // -----------------------------------------------------------------------
    // Test 3: Camera Noise / Color Cast — must be ≥ 70%
    // -----------------------------------------------------------------------

    public function test_ocr_benchmark_camera_noise(): void
    {
        $fixturePath = base_path('tests/Fixtures/kk_camera_noise.png');

        if (! file_exists($fixturePath)) {
            $this->markTestSkipped('kk_camera_noise.png not generated yet. Run tests/Fixtures/generate_test_kk_variants.php first.');
        }

        $this->runBenchmark('kk_camera_noise.png', 70.0);
    }

    // -----------------------------------------------------------------------
    // Core benchmark runner
    // -----------------------------------------------------------------------

    private function runBenchmark(string $filename, float $threshold): void
    {
        $fixturePath = base_path("tests/Fixtures/{$filename}");
        $this->assertFileExists($fixturePath, "Fixture {$filename} must exist.");

        $engine = app(TesseractOcrEngine::class);
        $parser = app(OcrParsingService::class);

        // ------------------------------------------------------------------
        // NOTE: Benchmark runs OCR directly on the fixture image WITHOUT the
        // GD ImagePreprocessor pipeline. Synthetic (computer-generated) test
        // images are already in ideal lossless PNG format — GD 2x upscale +
        // sharpen/contrast filters introduce interpolation blur that actually
        // DEGRADES Tesseract accuracy on synthetic fixtures.
        //
        // In production, ImagePreprocessor is invoked by OcrProcessingService
        // and benefits real scanned/photographed documents with EXIF rotation,
        // physical noise, and non-ideal exposure.
        // ------------------------------------------------------------------
        $ocrResult = $engine->run($fixturePath);

        $this->assertNotEmpty($ocrResult->rawText, "OCR raw text must not be empty for {$filename}");

        $parsed = $parser->parse($ocrResult->rawText, $ocrResult->confidence);
        $this->assertInstanceOf(ParsedOcrResult::class, $parsed);

        // --- Count accurate fields ---
        $totalFields   = 0;
        $accurateFields = 0;
        $errors        = [];

        // Header fields
        $headerChecks = [
            'kk_number' => [self::GROUND_TRUTH_KK_NUMBER, $parsed->kkNumber],
            'address'   => [self::GROUND_TRUTH_ADDRESS,   $parsed->address],
            'rt'        => [self::GROUND_TRUTH_RT,        $parsed->rt],
            'rw'        => [self::GROUND_TRUTH_RW,        $parsed->rw],
        ];

        foreach ($headerChecks as $field => [$expected, $actual]) {
            $totalFields++;
            if ($actual === $expected) {
                $accurateFields++;
            } else {
                $errors[] = "Header.{$field}: expected '{$expected}', got '{$actual}'";
            }
        }

        // Member fields
        foreach (self::GROUND_TRUTH_MEMBERS as $idx => $gt) {
            $member = $parsed->members[$idx - 1] ?? null;

            foreach (self::MEMBER_FIELDS as $field) {
                $totalFields++;

                if ($member === null) {
                    $errors[] = "Member #{$idx} missing entirely";
                    continue;
                }

                $memberFieldMap = [
                    'nik'             => $member->nik,
                    'nama'            => $member->nama,
                    'gender'          => $member->gender,
                    'birth_place'     => $member->birthPlace,
                    'birth_date'      => $member->birthDate,
                    'religion'        => $member->religion,
                    'education'       => $member->education,
                    'occupation'      => $member->occupation,
                    'marital_status'  => $member->maritalStatus,
                    'family_relation' => $member->familyRelation,
                    'ayah'            => $member->ayah,
                    'ibu'             => $member->ibu,
                ];

                $actual = $memberFieldMap[$field] ?? null;

                if ($actual === $gt[$field]) {
                    $accurateFields++;
                } else {
                    $errors[] = "Member #{$idx}.{$field}: expected '{$gt[$field]}', got '{$actual}'";
                }
            }
        }

        $accuracy = $totalFields > 0
            ? round(($accurateFields / $totalFields) * 100, 1)
            : 0.0;

        // Print benchmark table row to output
        $status = $accuracy >= $threshold ? '✅ PASS' : '❌ FAIL';
        $errorSummary = empty($errors) ? '-' : implode('; ', array_slice($errors, 0, 5));
        fwrite(STDOUT, PHP_EOL . sprintf(
            "┌─ BENCHMARK: %-30s ─────────────────────────┐\n│ Fields Accurate : %d / %d\n│ Accuracy        : %.1f%% (threshold: %.0f%%)\n│ Status          : %s\n│ Errors (sample) : %s\n└─────────────────────────────────────────────────────────────────────┘\n",
            $filename,
            $accurateFields,
            $totalFields,
            $accuracy,
            $threshold,
            $status,
            $errorSummary,
        ));

        $this->assertGreaterThanOrEqual(
            $threshold,
            $accuracy,
            "OCR accuracy for {$filename} is {$accuracy}%, below required {$threshold}%.\n".
            'Failing fields: '.implode(', ', $errors)
        );
    }
}
