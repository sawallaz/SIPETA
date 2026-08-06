<?php

namespace Tests\Feature\Phase5;

use App\Enums\OcrJobStatus;
use App\Models\OcrJob;
use App\Services\ImagePreprocessor;
use App\Services\KkDocumentUploadService;
use App\Services\OcrEngine;
use App\Services\OcrProcessingService;
use App\Services\OcrResult;
use App\Services\ParsedOcrResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\FakeOcrEngine;
use Tests\TestCase;

/**
 * Phase 5.5 — OCR parsing stage through the pipeline.
 *
 * Drives OcrProcessingService::parse() after extract() with a FakeOcrEngine,
 * proving the extracted raw text maps into a structured ParsedOcrResult and
 * that parsing persists nothing (ADR-009: OCR is an assistant).
 */
class OcrParsingPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const KK_TEXT = <<<'TXT'
NOMOR KARTU KELUARGA : 3207122801160001
ALAMAT : JL. MELATI NO. 5
RT/RW : 001/004

NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN STATUS PERKAWINAN STATUS HUBUNGAN DALAM KELUARGA
1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA
2 SITI AMINAH 3207124501010002 PEREMPUAN TANETE 05-04-2018 ISLAM SLTA/SEDERAJAT IBU RUMAH TANGGA KAWIN ISTRI
3 Andi Prasetyo 3207121503050003 LAKI-LAKI BOGOR 15-03-2005 ISLAM SMP PELAJAR/MAHASISWA BELUM KAWIN ANAK
TXT;

    private OcrProcessingService $service;

    private FakeOcrEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new FakeOcrEngine;
        $this->app->instance(OcrEngine::class, $this->engine);
        $this->service = app(OcrProcessingService::class);

        Storage::fake(KkDocumentUploadService::DISK);
        Storage::fake(ImagePreprocessor::DISK);
    }

    public function test_parse_after_extract_returns_structured_result(): void
    {
        $job = $this->uploadAndStart();
        $this->engine->result = new OcrResult(self::KK_TEXT, 91.0, 30, 120.0);
        $this->service->extract($job);

        $parsed = $this->service->parse($job);

        $this->assertInstanceOf(ParsedOcrResult::class, $parsed);
        $this->assertSame('3207122801160001', $parsed->kkNumber);
        $this->assertCount(3, $parsed->members);
        $this->assertSame(91.0, $parsed->confidence);
        $this->assertFalse($parsed->lowConfidence);
        $this->assertFalse($parsed->isEmpty());

        // The accessor exposes the same in-memory result.
        $this->assertSame($parsed, $this->service->parsedResult());

        // Job stays in its terminal extracted state; parsing never re-writes it.
        $this->assertSame(OcrJobStatus::SUCCESS, $job->fresh()->status);
    }

    public function test_parse_does_not_persist_structured_data(): void
    {
        $job = $this->uploadAndStart();
        $this->engine->result = new OcrResult(self::KK_TEXT, 91.0, 40, 120.0);
        $this->service->extract($job);

        $this->service->parse($job);

        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id,
            'status' => OcrJobStatus::SUCCESS->value,
            'raw_text' => self::KK_TEXT,
            'extracted_data' => null,
            'outcome' => null,
        ]);

        // Exactly one row exists and its extracted_data snapshot was never set.
        $this->assertSame(1, OcrJob::count());
        $this->assertNull($job->fresh()->extracted_data);
    }

    public function test_parse_without_extract_is_rejected(): void
    {
        $job = $this->uploadAndStart(); // PROCESSING, no engine result yet

        try {
            $this->service->parse($job);
            $this->fail('Expected InvalidArgumentException when parsing before extraction.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('must be SUCCESS or LOW_CONFIDENCE', $e->getMessage());
        }

        // Nothing persisted: the row is still the original PENDING.
        $this->assertSame(OcrJobStatus::PENDING, $job->fresh()->status);
        $this->assertNull($this->service->parsedResult());
    }

    public function test_parse_on_non_terminal_job_is_rejected(): void
    {
        foreach ([OcrJobStatus::PENDING, OcrJobStatus::FAILED] as $status) {
            $job = OcrJob::factory()->create(['status' => $status]);

            try {
                $this->service->parse($job);
                $this->fail('Expected InvalidArgumentException for a '.$status->value.' job.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('must be SUCCESS or LOW_CONFIDENCE', $e->getMessage());
            }

            $this->assertSame($status, $job->fresh()->status);
        }
    }

    public function test_parse_on_success_job_without_extraction_on_instance_is_rejected(): void
    {
        $job = OcrJob::factory()->create(['status' => OcrJobStatus::SUCCESS]);

        try {
            $this->service->parse($job);
            $this->fail('Expected InvalidArgumentException when extraction has not run on the service instance.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('extract()', $e->getMessage());
        }

        $this->assertSame(OcrJobStatus::SUCCESS, $job->fresh()->status);
        $this->assertNull($this->service->parsedResult());
    }

    public function test_low_confidence_extraction_parses_into_low_confidence_result(): void
    {
        $job = $this->uploadAndStart();
        $this->engine->result = new OcrResult(self::KK_TEXT, 45.0, 40, 120.0);
        $this->service->extract($job);

        $parsed = $this->service->parse($job);

        $this->assertTrue($parsed->lowConfidence);
        $this->assertSame('3207122801160001', $parsed->kkNumber);
        $this->assertCount(3, $parsed->members);
        $this->assertSame(OcrJobStatus::LOW_CONFIDENCE, $job->fresh()->status);
    }

    private function uploadAndStart(): OcrJob
    {
        $job = app(KkDocumentUploadService::class)->upload(UploadedFile::fake()->image('kk-scan.png', 800, 600));
        $this->service->start($job);

        return $job;
    }
}
