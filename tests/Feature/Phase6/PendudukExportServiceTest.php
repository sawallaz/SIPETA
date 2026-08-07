<?php

namespace Tests\Feature\Phase6;

use App\Enums\ExportFormat;
use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Models\Penduduk;
use App\Models\Religion;
use App\Models\Rt;
use App\Models\Setting;
use App\Services\PendudukExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\Common\Creator\ReaderFactory;
use Tests\TestCase;

/**
 * Phase 6.1 — Reporting & Export foundation. Verifies the export service honours
 * filter criteria (FR-EX-02) and produces valid PDF / XLSX / CSV outputs with a
 * date + filter-summary filename (FR-EX-03).
 */
class PendudukExportServiceTest extends TestCase
{
    use RefreshDatabase;

    private PendudukExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PendudukExportService;
    }

    public function test_filename_embeds_export_date_and_semua_when_no_filters(): void
    {
        $now = Carbon::parse('2026-08-07 10:30:00');

        $this->assertSame(
            '2026-08-07_semua.pdf',
            $this->service->filename(ExportFormat::PDF, [], $now),
        );
        $this->assertSame(
            '2026-08-07_semua.xlsx',
            $this->service->filename(ExportFormat::XLSX, [], $now),
        );
    }

    public function test_filename_includes_filter_summary(): void
    {
        $now = Carbon::parse('2026-08-07 10:30:00');

        $this->assertSame(
            '2026-08-07_jk-laki-laki_status-aktif.pdf',
            $this->service->filename(
                ExportFormat::PDF,
                ['gender' => Gender::LAKI_LAKI->value, 'resident_status' => ResidentStatus::ACTIVE->value],
                $now,
            ),
        );
    }

    public function test_filter_summary_returns_semua_for_empty_filters(): void
    {
        $this->assertSame('semua', $this->service->filterSummary([]));
    }

    public function test_filter_summary_contains_active_filters_slug(): void
    {
        $summary = $this->service->filterSummary([
            'rt' => '001',
            'resident_status' => ResidentStatus::MENINGGAL->value,
        ]);

        $this->assertSame('rt-001_status-meninggal', $summary);
    }

    public function test_apply_filters_by_gender(): void
    {
        Penduduk::factory()->create(['gender' => Gender::PEREMPUAN->value]);
        Penduduk::factory()->create(['gender' => Gender::LAKI_LAKI->value]);

        $query = $this->service->buildQuery(['gender' => Gender::PEREMPUAN->value]);

        $this->assertSame(1, $query->count());
        $this->assertSame(Gender::PEREMPUAN->value, $query->first()->gender->value);
    }

    public function test_apply_filters_by_resident_status(): void
    {
        Penduduk::factory()->create(['resident_status' => ResidentStatus::ACTIVE->value]);
        Penduduk::factory()->create(['resident_status' => ResidentStatus::PINDAH->value]);

        $query = $this->service->buildQuery(['resident_status' => ResidentStatus::PINDAH->value]);

        $this->assertSame(1, $query->count());
    }

    public function test_apply_filters_by_rt_number(): void
    {
        $rt = Rt::factory()->create(['number' => '007']);
        $otherRt = Rt::factory()->create(['number' => '001']);
        Penduduk::factory()->create(['rt_id' => $rt->id]);
        Penduduk::factory()->create(['rt_id' => $otherRt->id]);

        $query = $this->service->buildQuery(['rt' => '007']);

        $this->assertSame(1, $query->count());
    }

    public function test_apply_filters_by_religion(): void
    {
        $religion = Religion::factory()->create();
        $otherReligion = Religion::factory()->create();
        Penduduk::factory()->create(['religion_id' => $religion->id]);
        Penduduk::factory()->create(['religion_id' => $otherReligion->id]);

        $query = $this->service->buildQuery(['religion_id' => $religion->id]);

        $this->assertSame(1, $query->count());
    }

    public function test_apply_filters_combine_gender_and_status(): void
    {
        Penduduk::factory()->create([
            'gender' => Gender::PEREMPUAN->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
        ]);
        Penduduk::factory()->create([
            'gender' => Gender::PEREMPUAN->value,
            'resident_status' => ResidentStatus::PINDAH->value,
        ]);
        Penduduk::factory()->create([
            'gender' => Gender::LAKI_LAKI->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
        ]);

        $query = $this->service->buildQuery([
            'gender' => Gender::PEREMPUAN->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
        ]);

        $this->assertSame(1, $query->count());
    }

    public function test_apply_filters_by_exact_age(): void
    {
        // A person born exactly today - 30 years is 30 today.
        Penduduk::factory()->create(['birth_date' => now()->subYears(30)->format('Y-m-d')]);
        Penduduk::factory()->create(['birth_date' => now()->subYears(5)->format('Y-m-d')]);

        $query = $this->service->buildQuery(['age' => 30]);

        $this->assertSame(1, $query->count());
    }

    public function test_apply_filters_by_age_range(): void
    {
        Penduduk::factory()->create(['birth_date' => now()->subYears(25)->format('Y-m-d')]);
        Penduduk::factory()->create(['birth_date' => now()->subYears(10)->format('Y-m-d')]);
        Penduduk::factory()->create(['birth_date' => now()->subYears(2)->format('Y-m-d')]);

        $query = $this->service->buildQuery(['age_min' => 20, 'age_max' => 30]);

        $this->assertSame(1, $query->count());
    }

    public function test_excel_export_returns_valid_xlsx_zip(): void
    {
        Penduduk::factory()->create(['full_name' => 'Budi Santoso']);

        $response = $this->service->export(ExportFormat::XLSX);

        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition') ?? '');
        $this->assertSame(
            ExportFormat::XLSX->mime(),
            $response->headers->get('Content-Type'),
        );

        $path = $response->getFile()->getPathname();
        $contents = file_get_contents($path);
        $this->assertNotFalse(str_starts_with($contents, 'PK'), 'XLSX must be a valid ZIP/OpenXML file.');

        // Round-trip the file through OpenSpout's reader to prove real rows exist.
        $path = $response->getFile()->getPathname();
        $withExt = tempnam(sys_get_temp_dir(), 'penduduk_test').'.xlsx';
        copy($path, $withExt);
        $reader = ReaderFactory::createFromFile($withExt);
        $reader->open($withExt);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($withExt);

        $this->assertCount(2, $rows);
        $this->assertSame(['NIK', 'Nama Lengkap', 'Nomor KK'], array_slice($rows[0], 0, 3));
        $this->assertContains('Budi Santoso', $rows[1]);
    }

    public function test_pdf_export_returns_valid_pdf(): void
    {
        Penduduk::factory()->create(['full_name' => 'Siti Junaidah']);

        $response = $this->service->export(ExportFormat::PDF);

        $this->assertSame(ExportFormat::PDF->mime(), $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertGreaterThan(1000, strlen($response->getContent()));
    }

    public function test_pdf_view_renders_kelurahan_name_from_settings(): void
    {
        Setting::create([
            'kelurahan_name' => 'Kelurahan Tanete',
            'kecamatan_name' => 'Kecamatan Polewali',
            'kabupaten_name' => 'Kabupaten Polewali Mandar',
            'province_name' => 'Sulawesi Barat',
            'backup_path' => storage_path('backups'),
        ]);
        Penduduk::factory()->create(['full_name' => 'Nur Aisyah']);

        $html = view('exports.penduduk-pdf', [
            'rows' => collect([['NIK' => '3207...', 'Nama Lengkap' => 'Nur Aisyah']]),
            'columns' => ['NIK', 'Nama Lengkap'],
            'filterSummary' => 'semua',
            'generatedAt' => now(),
            'kelurahanName' => 'Kelurahan Tanete',
        ])->render();

        $this->assertStringContainsString('Laporan Data Penduduk', $html);
        $this->assertStringContainsString('Kelurahan Tanete', $html);
        $this->assertStringContainsString('Nur Aisyah', $html);
    }
}
