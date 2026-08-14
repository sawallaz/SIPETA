<?php

namespace App\Services;

use App\Enums\ExportFormat;
use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Models\Penduduk;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 6.1 — Reporting & Export foundation.
 *
 * Exports Penduduk data to PDF (DomPDF), XLSX / CSV (OpenSpout, streamed row-by-row
 * to a temp file via chunk()). Exports always respect the active filter criteria
 * (FR-EX-02) and the generated filename always embeds the export date and a
 * human-readable filter summary (FR-EX-03).
 */
class PendudukExportService
{
    /** Ordered export columns: DB/key => human label. */
    protected const COLUMNS = [
        'nik' => 'NIK',
        'full_name' => 'Nama Lengkap',
        'kk_number' => 'Nomor KK',
        'gender' => 'Jenis Kelamin',
        'birth_place' => 'Tempat Lahir',
        'birth_date' => 'Tanggal Lahir',
        'age' => 'Usia (th)',
        'rt' => 'RT',
        'area_unit' => 'RW',
        'resident_status' => 'Status',
        'religion' => 'Agama',
        'education' => 'Pendidikan',
        'occupation' => 'Pekerjaan',
    ];

    /**
     * Apply supported filter criteria to a query. Keys mirror the projected
     * Penduduk table filters (RT, RW/Lingkungan, gender, religion, education,
     * occupation, resident status, and age range).
     *
     * Supported keys (all optional):
     *   string $rt            -> exact RT number
     *   int    $area_unit     -> area unit (RW/Lingkungan) id
     *   string $gender        -> Gender enum value
     *   int    $religion_id
     *   int    $education_id
     *   int    $occupation_id
     *   string $resident_status -> ResidentStatus enum value
     *   int    $age           -> exact age in years (computed, never stored)
     *   int    $age_min
     *   int    $age_max
     */
    public function applyFilters(Builder $query, array $filters = []): Builder
    {
        $query->with([
            'kartuKeluarga:id,kk_number',
            'rt.areaUnit',
            'religion:id,name',
            'education:id,name',
            'occupation:id,name',
        ]);

        if (($rt = $filters['rt'] ?? null) !== null) {
            $query->whereHas('rt', fn ($q) => $q->where('number', (string) $rt));
        }

        if (($areaUnit = $filters['area_unit'] ?? null) !== null) {
            $query->whereHas('rt', fn ($q) => $q->where('area_unit_id', (int) $areaUnit));
        }

        if (($gender = $filters['gender'] ?? null) !== null) {
            $query->where('gender', Gender::tryFrom($gender)?->value ?? $gender);
        }

        $query->when($filters['religion_id'] ?? null, fn ($q, $v) => $q->where('religion_id', (int) $v));
        $query->when($filters['education_id'] ?? null, fn ($q, $v) => $q->where('education_id', (int) $v));
        $query->when($filters['occupation_id'] ?? null, fn ($q, $v) => $q->where('occupation_id', (int) $v));

        if (($status = $filters['resident_status'] ?? null) !== null) {
            $query->where('resident_status', ResidentStatus::tryFrom($status)?->value ?? $status);
        }

        $age = $filters['age'] ?? null;
        $ageMin = $filters['age_min'] ?? null;
        $ageMax = $filters['age_max'] ?? null;

        if ($age !== null) {
            $this->applyExactAge($query, (int) $age);
        } elseif ($ageMin !== null || $ageMax !== null) {
            $this->applyAgeRange($query, $ageMin, $ageMax);
        }

        return $query;
    }

    /**
     * Build the filtered export query.
     */
    public function buildQuery(array $filters = []): Builder
    {
        return $this->applyFilters(Penduduk::query(), $filters);
    }

    /**
     * Export an already-filtered query (e.g. the Filament table's live query).
     * Filter metadata is used only for the filename. (FR-EX-02/03)
     */
    public function exportQuery(Builder $query, ExportFormat $format, array $filters = [], ?string $name = null): Response
    {
        $query->with([
            'kartuKeluarga:id,kk_number',
            'rt.areaUnit',
            'religion:id,name',
            'education:id,name',
            'occupation:id,name',
        ]);
        $filename = $name ?? $this->filename($format, $filters);

        return match ($format) {
            ExportFormat::PDF => $this->pdfResponse($query, $filters, $filename),
            ExportFormat::XLSX => $this->tabularAs($query, XlsxWriter::class, $filename, ExportFormat::XLSX),
            ExportFormat::CSV => $this->tabularAs($query, CsvWriter::class, $filename, ExportFormat::CSV),
        };
    }

    /**
     * Stream the requested export format as a downloadable HTTP response.
     */
    public function export(ExportFormat $format, array $filters = [], ?string $name = null): Response
    {
        $query = $this->buildQuery($filters);
        $filename = $name ?? $this->filename($format, $filters);

        return match ($format) {
            ExportFormat::PDF => $this->pdfResponse($query, $filters, $filename),
            ExportFormat::XLSX => $this->tabularAs($query, XlsxWriter::class, $filename, ExportFormat::XLSX),
            ExportFormat::CSV => $this->tabularAs($query, CsvWriter::class, $filename, ExportFormat::CSV),
        };
    }

    /**
     * The export filename: <date>_<filter summary>.<ext>. (FR-EX-03)
     */
    public function filename(ExportFormat $format, array $filters = [], ?Carbon $now = null): string
    {
        $now ??= now();
        $summary = $this->filterSummary($filters);

        return sprintf('%s_%s.%s', $now->format('Y-m-d'), $summary, $format->value);
    }

    /**
     * Human-readable slug of the active filters for the filename; "semua" when none.
     */
    public function filterSummary(array $filters): string
    {
        $parts = [];

        if (($filters['rt'] ?? null) !== null) {
            $parts[] = 'rt-'.strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $filters['rt']));
        }
        if (($filters['area_unit'] ?? null) !== null) {
            $parts[] = 'rw-'.strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $filters['area_unit']));
        }
        if (($filters['gender'] ?? null) !== null) {
            $g = match (Gender::tryFrom($filters['gender'])) {
                Gender::LAKI_LAKI => 'Laki-laki',
                Gender::PEREMPUAN => 'Perempuan',
                default => (string) $filters['gender'],
            };
            $parts[] = 'jk-'.strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $g));
        }
        if (($filters['religion_id'] ?? null) !== null) {
            $parts[] = 'agama-'.$filters['religion_id'];
        }
        if (($filters['education_id'] ?? null) !== null) {
            $parts[] = 'pendidikan-'.$filters['education_id'];
        }
        if (($filters['occupation_id'] ?? null) !== null) {
            $parts[] = 'pekerjaan-'.$filters['occupation_id'];
        }
        if (($filters['resident_status'] ?? null) !== null) {
            $s = match (ResidentStatus::tryFrom($filters['resident_status'])) {
                ResidentStatus::ACTIVE => 'Aktif',
                ResidentStatus::PINDAH => 'Pindah',
                ResidentStatus::MENINGGAL => 'Meninggal',
                default => (string) $filters['resident_status'],
            };
            $parts[] = 'status-'.strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $s));
        }
        if (($filters['age'] ?? null) !== null) {
            $parts[] = 'usia-'.$filters['age'];
        }
        if (($filters['age_min'] ?? null) !== null || ($filters['age_max'] ?? null) !== null) {
            $min = $filters['age_min'] ?? '0';
            $max = $filters['age_max'] ?? 'max';
            $parts[] = "usia-{$min}to{$max}";
        }

        return $parts === [] ? 'semua' : implode('_', $parts);
    }

    protected function applyExactAge(Builder $query, int $age): void
    {
        // Exact age N = the shared age-range predicate with min == max.
        // Single source of the birth-date translation (Phase UI-2).
        $query->ageRange($age, $age);
    }

    protected function applyAgeRange(Builder $query, ?int $ageMin, ?int $ageMax): void
    {
        // Delegates to Penduduk::scopeAgeRange() — the SAME predicate the
        // Filament table age filters use, so it is never implemented twice.
        $query->ageRange($ageMin, $ageMax);
    }

    protected function pdfResponse(Builder $query, array $filters, string $filename): Response
    {
        $rows = $this->collectRows($query);

        $setting = Setting::query()->first();

        $logoData = null;

        if ($setting?->logo_path) {
            $logoDisk = Storage::disk('local');

            if ($logoDisk->exists($setting->logo_path)) {
                $logoPath = $logoDisk->path($setting->logo_path);
                $logoMime = mime_content_type($logoPath);

                $logoData = 'data:'.$logoMime.';base64,'.
                    base64_encode(file_get_contents($logoPath));
            }
        }

        $pdf = Pdf::loadView('exports.penduduk-pdf', [
            'rows' => $rows,
            'columns' => array_values(self::COLUMNS),
            'filterSummary' => $this->filterSummary($filters),
            'generatedAt' => now(),

            'kelurahanName' => $setting?->kelurahan_name,
            'kecamatanName' => $setting?->kecamatan_name,
            'kabupatenName' => $setting?->kabupaten_name,
            'provinceName' => $setting?->province_name,

            'logoData' => $logoData,
        ])->setPaper('a4', 'landscape');

        return new Response(
            $pdf->output(),
            Response::HTTP_OK,
            $this->downloadHeaders($filename, ExportFormat::PDF->mime()),
        );
    }

    /**
     * Write XLSX or CSV to a temp file via OpenSpout, then return it as a
     * BinaryFileResponse that deletes the temp file after send.
     *
     * @param  class-string  $writerClass
     */
    protected function tabularAs(Builder $query, string $writerClass, string $filename, ExportFormat $format): BinaryFileResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'penduduk_');

        $writer = new $writerClass($this->writerOptions($writerClass));
        $writer->openToFile($tmp);
        $writer->addRow(Row::fromValues($this->headerRow()));
        $query->chunkById(500, function (Collection $chunk) use ($writer): void {
            foreach ($chunk as $penduduk) {
                $writer->addRow(Row::fromValues($this->mapRow($penduduk)));
            }
        });
        $writer->close();

        $response = new BinaryFileResponse($tmp);
        $response->headers->set('Content-Type', $format->mime());
        $response->setContentDisposition('attachment', $filename, '');
        $response->deleteFileAfterSend(true);
        $response->prepare(request());

        return $response;
    }

    protected function writerOptions(string $writerClass): object
    {
        return $writerClass === XlsxWriter::class ? new XlsxOptions : new CsvOptions;
    }

    private function headerRow(): array
    {
        return array_values(self::COLUMNS);
    }

    private function collectRows(Builder $query): Collection
    {
        return $query->get()->map(fn (Penduduk $penduduk) => $this->mapRow($penduduk));
    }

    /** Map one Penduduk to the ordered export row (display-friendly values). */
    private function mapRow(Penduduk $penduduk): array
    {
        $gender = match ($penduduk->gender) {
            Gender::LAKI_LAKI => 'Laki-laki',
            Gender::PEREMPUAN => 'Perempuan',
            default => $penduduk->gender?->value ?? '-',
        };
        $status = match ($penduduk->resident_status) {
            ResidentStatus::ACTIVE => 'Aktif',
            ResidentStatus::PINDAH => 'Pindah',
            ResidentStatus::MENINGGAL => 'Meninggal',
            default => $penduduk->resident_status?->value ?? '-',
        };

        return [
            self::COLUMNS['nik'] => $penduduk->nik,
            self::COLUMNS['full_name'] => $penduduk->full_name,
            self::COLUMNS['kk_number'] => $penduduk->kartuKeluarga?->kk_number ?? '-',
            self::COLUMNS['gender'] => $gender,
            self::COLUMNS['birth_place'] => $penduduk->birth_place,
            self::COLUMNS['birth_date'] => $penduduk->birth_date?->format('d-m-Y') ?? '-',
            self::COLUMNS['age'] => (string) $penduduk->age,
            self::COLUMNS['rt'] => $penduduk->rt?->number ?? '-',
            self::COLUMNS['area_unit'] => $penduduk->rt?->areaUnit?->display_label ?? '-',
            self::COLUMNS['resident_status'] => $status,
            self::COLUMNS['religion'] => $penduduk->religion?->name ?? '-',
            self::COLUMNS['education'] => $penduduk->education?->name ?? '-',
            self::COLUMNS['occupation'] => $penduduk->occupation?->name ?? '-',
        ];
    }

    private function downloadHeaders(string $filename, string $mime): array
    {
        return [
            'Content-Type' => $mime,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];
    }
}
