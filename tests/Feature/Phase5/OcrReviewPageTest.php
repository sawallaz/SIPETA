<?php

namespace Tests\Feature\Phase5;

use App\Enums\OcrJobStatus;
use App\Filament\Resources\OcrJobs\Pages\ReviewOcrJob;
use App\Models\OcrJob;
use App\Models\User;
use App\Services\OcrParsingService;
use App\Services\OcrReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 5.6 — OCR review page.
 *
 * Proves the operator review workflow: the page loads for a reviewable job,
 * displays every parsed field, highlights missing required fields and
 * low-confidence values (.ai/ocr.md §5), rejects non-reviewable jobs, and
 * runs the pre-approval validation gate — all without a single database
 * write (ADR-009: OCR is an assistant; the import is a later phase).
 */
class OcrReviewPageTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function job(array $overrides = []): OcrJob
    {
        return OcrJob::factory()->create(array_replace([
            'status' => OcrJobStatus::SUCCESS,
            'confidence' => 92.5,
            'raw_text' => self::KK_TEXT,
        ], $overrides));
    }

    private function url(int $jobId): string
    {
        return ReviewOcrJob::getUrl(['record' => $jobId]);
    }

    public function test_review_page_loads(): void
    {
        $job = $this->job();

        $this->get($this->url($job->id))
            ->assertOk()
            ->assertSee('Review Hasil OCR');
    }

    public function test_parsed_fields_are_displayed(): void
    {
        $job = $this->job();

        $this->get($this->url($job->id))
            ->assertOk()
            ->assertSee('3207122801160001')
            ->assertSee('JL. MELATI NO. 5')
            ->assertSee('BUDI SANTOSO')
            ->assertSee('SITI AMINAH')
            ->assertSee('Andi Prasetyo');
    }

    public function test_missing_required_fields_are_highlighted(): void
    {
        $missingKk = preg_replace('/NOMOR KARTU KELUARGA : .*\n/', '', self::KK_TEXT);
        $job = $this->job(['raw_text' => $missingKk]);

        $this->get($this->url($job->id))
            ->assertOk()
            ->assertSee('Field wajib belum diisi')
            ->assertSee('Nomor KK wajib diisi');
    }

    public function test_low_confidence_values_are_highlighted(): void
    {
        $job = $this->job(['confidence' => 55.0]);

        $this->get($this->url($job->id))
            ->assertOk()
            ->assertSee('Confidence rendah — periksa ulang')
            ->assertSee('Harap periksa');
    }

    public function test_high_confidence_is_not_flagged(): void
    {
        $job = $this->job(['confidence' => 98.0]);

        $this->get($this->url($job->id))
            ->assertOk()
            ->assertDontSee('Harap periksa');
    }

    public function test_validation_succeeds_and_reports_ready_to_import(): void
    {
        $job = $this->job();

        Livewire::test(ReviewOcrJob::class, ['record' => $job->id])
            ->call('validateReview')
            ->assertNotified('Validasi berhasil');
    }

    public function test_validation_fails_on_malformed_operator_correction(): void
    {
        $job = $this->job();

        $parsed = (new OcrParsingService)->parse(self::KK_TEXT, 92.5);
        $state = (new OcrReviewService)->validate($parsed)->correctedData();
        $state['members'][0]['nik'] = '123';

        Livewire::test(ReviewOcrJob::class, ['record' => $job->id])
            ->fillForm($state)
            ->call('validateReview')
            ->assertHasFormErrors(['members.0.nik']);
    }

    public function test_non_reviewable_job_is_rejected_without_form(): void
    {
        $job = $this->job([
            'status' => OcrJobStatus::PENDING,
            'raw_text' => null,
            'confidence' => 0.0,
        ]);

        $this->get($this->url($job->id))
            ->assertOk()
            ->assertSee('Hasil OCR belum dapat direview')
            ->assertDontSee('Validasi Data');
    }

    public function test_review_never_writes_to_the_database(): void
    {
        $job = $this->job();

        $this->get($this->url($job->id))->assertOk();

        Livewire::test(ReviewOcrJob::class, ['record' => $job->id])
            ->call('validateReview')
            ->assertNotified('Validasi berhasil');

        $this->assertDatabaseHas('ocr_jobs', ['id' => $job->id, 'status' => OcrJobStatus::SUCCESS->value, 'raw_text' => self::KK_TEXT]);
        $this->assertDatabaseCount('kartu_keluarga', 1); // only the factory's implicit one
        $this->assertDatabaseCount('penduduk', 0);
    }
}
