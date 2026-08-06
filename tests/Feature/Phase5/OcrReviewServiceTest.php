<?php

namespace Tests\Feature\Phase5;

use App\Enums\OcrJobStatus;
use App\Models\OcrJob;
use App\Services\OcrParsingService;
use App\Services\OcrReviewResult;
use App\Services\OcrReviewService;
use App\Services\ParsedOcrResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5.6 — OCR review & validation service.
 *
 * Proves OcrReviewService is the pre-approval validation gate (ADR-009: OCR
 * is an assistant): a complete parsed result validates, an invalid parsed
 * result is rejected, operator corrections can both fix and (when mistaken)
 * break validation, and missing required fields are reported for the review
 * form to highlight. Pure in-memory — nothing is ever written.
 */
class OcrReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Complete, parseable KK scan (same fixture as 5.5). */
    private const KK_TEXT = <<<'TXT'
NOMOR KARTU KELUARGA : 3207122801160001
NAMA KEPALA KELUARGA : BUDI SANTOSO
ALAMAT : JL. MELATI NO. 5
RT/RW : 001/004
KODE POS : 16340

NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN STATUS PERKAWINAN STATUS HUBUNGAN DALAM KELUARGA
1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA
2 SITI AMINAH 3207124501010002 PEREMPUAN TANETE 05-04-2018 ISLAM SLTA/SEDERAJAT IBU RUMAH TANGGA KAWIN ISTRI
3 Andi Prasetyo 3207121503050003 LAKI-LAKI BOGOR 15-03-2005 ISLAM SMP PELAJAR/MAHASISWA BELUM KAWIN ANAK
TXT;

    private OcrReviewService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OcrReviewService;
    }

    private function parse(string $text, float $confidence = 92.5): ParsedOcrResult
    {
        return (new OcrParsingService)->parse($text, $confidence);
    }

    public function test_validation_succeeds_for_complete_parsed_result(): void
    {
        $result = $this->service->validate($this->parse(self::KK_TEXT));

        $this->assertInstanceOf(OcrReviewResult::class, $result);
        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->errors());
        $this->assertSame('3207122801160001', $result->correctedData()['kk_number']);
        $this->assertCount(3, $result->correctedData()['members']);
    }

    public function test_validation_rejects_invalid_parsed_result_missing_kk_number(): void
    {
        $missingKk = preg_replace('/^NOMOR KARTU KELUARGA : \S+\n/m', '', self::KK_TEXT);

        $result = $this->service->validate($this->parse($missingKk));

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('kk_number', $result->errors());
        $this->assertSame('Nomor KK wajib diisi', $result->errors()['kk_number']);
    }

    public function test_validation_rejects_more_than_one_member_required(): void
    {
        $emptyMembers = preg_replace('/^1 .*\n2 .*\n3 .*\n?$/m', '', self::KK_TEXT, 1);
        $parsed = $this->parse($emptyMembers);

        $result = $this->service->validate($parsed);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('members', $result->errors());
    }

    public function test_operator_nik_correction_that_is_malformed_breaks_validation(): void
    {
        $result = $this->service->validate(
            $this->parse(self::KK_TEXT),
            ['members' => [0 => ['nik' => '123']]],
        );

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('members.0.nik', $result->errors());
        $this->assertSame('NIK anggota ke-1 harus 16 digit angka', $result->errors()['members.0.nik']);
    }

    public function test_operator_gender_correction_outside_allowed_set_is_rejected(): void
    {
        $result = $this->service->validate(
            $this->parse(self::KK_TEXT),
            ['members' => [1 => ['gender' => 'LAKI-LAKI']]],
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Jenis kelamin', $result->errors()['members.1.gender']);
    }

    public function test_corrections_fix_parse_problem_and_pass(): void
    {
        $missingKk = preg_replace('/NOMOR KARTU KELUARGA : \S+\n/', '', self::KK_TEXT);

        $result = $this->service->validate(
            $this->parse($missingKk),
            ['kk_number' => '3207122801160001'],
        );

        $this->assertTrue($result->isValid(), implode(', ', $result->errors()));
        $this->assertSame('3207122801160001', $result->correctedData()['kk_number']);
    }

    public function test_corrections_override_parsed_values_in_effective_data(): void
    {
        $result = $this->service->validate(
            $this->parse(self::KK_TEXT),
            ['address' => 'JL. BARU NO. 9', 'members' => [0 => ['nama' => 'EDIT NAMA']]],
        );

        $this->assertSame('JL. BARU NO. 9', $result->correctedData()['address']);
        $this->assertSame('EDIT NAMA', $result->correctedData()['members'][0]['nama']);
    }

    public function test_missing_required_fields_are_reported_as_labels(): void
    {
        $parsed = $this->parse(preg_replace('/NOMOR KARTU KELUARGA : .*\n/', '', self::KK_TEXT));

        $missing = $this->service->missingRequiredFields(
            $this->service->validate($parsed)->correctedData(),
        );

        $this->assertContains('Nomor KK wajib diisi', $missing);
    }

    public function test_no_missing_required_fields_for_complete_parsed_result(): void
    {
        $missing = $this->service->missingRequiredFields(
            $this->service->validate($this->parse(self::KK_TEXT))->correctedData(),
        );

        $this->assertSame([], $missing);
    }

    public function test_confidence_band_follows_dot_ai_ocr_section_5(): void
    {
        $this->assertNull(OcrReviewService::confidenceBand(95.0));
        $this->assertNull(OcrReviewService::confidenceBand(90.0));
        $this->assertSame('warning', OcrReviewService::confidenceBand(80.0));
        $this->assertSame('warning', OcrReviewService::confidenceBand(70.0));
        $this->assertSame('danger', OcrReviewService::confidenceBand(69.9));
        $this->assertSame('danger', OcrReviewService::confidenceBand(55.0));
    }

    public function test_is_reviewable_gates_terminal_states_with_raw_text(): void
    {
        $this->assertTrue(OcrReviewService::isReviewable(OcrJob::factory()->make([
            'status' => OcrJobStatus::SUCCESS, 'raw_text' => 'text',
        ])));
        $this->assertTrue(OcrReviewService::isReviewable(OcrJob::factory()->make([
            'status' => OcrJobStatus::LOW_CONFIDENCE, 'raw_text' => 'text',
        ])));

        $this->assertFalse(OcrReviewService::isReviewable(OcrJob::factory()->make([
            'status' => OcrJobStatus::PENDING, 'raw_text' => 'text',
        ])));
        $this->assertFalse(OcrReviewService::isReviewable(OcrJob::factory()->make([
            'status' => OcrJobStatus::SUCCESS, 'raw_text' => null,
        ])));
    }
}
