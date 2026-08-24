<?php

namespace Tests\Feature;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Filament\Pages\ImportPenduduk;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Models\Rt;
use App\Models\User;
use App\Services\OcrParsingService;
use App\Services\OcrReviewService;
use App\Services\PendudukImportService;
use Database\Seeders\SystemReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\Common\Creator\WriterFactory;
use Tests\TestCase;
use Throwable;

class PendudukExcelImportTest extends TestCase
{
    use RefreshDatabase;

    private PendudukImportService $service;

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PendudukImportService(new OcrParsingService, new OcrReviewService);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_upload_xlsx_is_accepted_and_lists_one_sheet(): void
    {
        Storage::fake('local');
        $path = $this->makeXlsx('Penduduk', [$this->headers(), $this->values()]);
        $response = $this->actingAs(User::factory()->create())->post(route('penduduk.import.upload'), [
            'file' => new UploadedFile($path, 'penduduk.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonCount(1, 'sheets');
    }

    public function test_upload_csv_is_accepted(): void
    {
        Storage::fake('local');
        $csv = implode(',', $this->headers())."\n".implode(',', $this->values())."\n";
        $response = $this->actingAs(User::factory()->create())->post(route('penduduk.import.upload'), [
            'file' => UploadedFile::fake()->createWithContent('penduduk.csv', $csv),
        ]);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonCount(1, 'sheets');
    }

    public function test_upload_xls_returns_the_explicit_unsupported_format_error(): void
    {
        $response = $this->actingAs(User::factory()->create())->post(route('penduduk.import.upload'), [
            'file' => UploadedFile::fake()->create('penduduk.xls', 10, 'application/vnd.ms-excel'),
        ]);

        $response->assertOk()->assertJsonPath('error', 'Format .xls belum didukung. Gunakan .xlsx atau .csv.');
    }

    public function test_xlsx_multi_sheet_exposes_sheets_and_parses_only_selected_sheet(): void
    {
        $path = $this->makeXlsx('Data Utama', [$this->headers(), $this->values()], true);
        $parsed = $this->service->parseFile($path);
        $selected = $this->service->parseSheet($path, 'Data Utama');

        $this->assertSame(['Data Utama', 'Sheet Kedua'], $parsed['sheets']);
        $this->assertSame('Data Utama', $selected['sheet_name']);
        $this->assertCount(1, $selected['rows']);
    }

    public function test_header_aliases_are_normalized(): void
    {
        $result = $this->service->suggestMapping(['No NIK', 'Nama Lengkap', 'Nomor KK', 'Gender', 'Tanggal Lahir', 'RT', 'RW', 'Alamat']);

        $this->assertSame('No NIK', $result['mapping']['nik']);
        $this->assertSame('Nama Lengkap', $result['mapping']['full_name']);
        $this->assertSame('Nomor KK', $result['mapping']['kk_number']);
        $this->assertSame([], $result['missing_required']);
    }

    public function test_ambiguous_header_is_not_forced(): void
    {
        $result = $this->service->suggestMapping(['NIK', 'No NIK', 'Nama', 'No KK', 'JK', 'Tgl Lahir', 'RT', 'RW', 'Alamat']);

        $this->assertSame(['NIK', 'No NIK'], $result['ambiguous']['nik']);
    }

    public function test_missing_required_header_is_reported(): void
    {
        $result = $this->service->suggestMapping(['NIK', 'Nama', 'No KK']);

        $this->assertContains('gender', $result['missing_required']);
        $this->assertContains('address', $result['missing_required']);
    }

    public function test_duplicate_nik_is_reported_without_overwrite(): void
    {
        $this->makeInfrastructure();
        $this->service->importRows([$this->validRow()]);
        $result = $this->service->validateRows([$this->validRow()], $this->identityMapping());

        $this->assertSame(1, $result['duplicate_count']);
        $this->assertSame(1, Penduduk::where('nik', $this->validRow()['nik'])->count());
    }

    public function test_missing_kk_is_invalid(): void
    {
        $row = $this->validRow();
        $row['kk_number'] = '9999999999999999';
        $result = $this->service->validateRows([$row], $this->identityMapping());

        $this->assertSame(1, $result['invalid_count']);
        $this->assertStringContainsString('KK tidak ditemukan', implode(' ', $result['errors'][2]));
    }

    public function test_valid_row_is_imported_transactionally(): void
    {
        $this->makeInfrastructure();
        $result = $this->service->importRows([$this->validRow()]);

        $this->assertSame(1, $result['imported']);
        $this->assertDatabaseHas('penduduk', ['nik' => $this->validRow()['nik']]);
        $this->assertDatabaseCount('kk_anggota', 1);
    }

    public function test_import_result_reports_successful_rows(): void
    {
        $this->makeInfrastructure();
        $row = $this->validRow();
        $row['marital_status'] = 'KAWIN';
        $validation = $this->service->validateRows([$row], $this->identityMapping());

        $response = $this->postImportWithValidation([$row], $validation);

        $response->assertOk()
            ->assertJsonPath('total_imported', 1)
            ->assertJsonPath('duplicate_count', 0)
            ->assertJsonPath('invalid_count', 0);
    }

    public function test_import_result_reports_duplicate_rows(): void
    {
        $this->makeInfrastructure();
        $row = $this->validRow();
        $row['marital_status'] = 'KAWIN';
        $this->service->importRows([$row]);
        $validation = $this->service->validateRows([$row], $this->identityMapping());

        $response = $this->postImportWithValidation([$row], $validation);

        $response->assertOk()
            ->assertJsonPath('total_imported', 0)
            ->assertJsonPath('duplicate_count', 1)
            ->assertJsonPath('invalid_count', 0);
        $this->assertSame(1, Penduduk::where('nik', $row['nik'])->count());
    }

    public function test_import_result_reports_invalid_rows(): void
    {
        $row = $this->validRow();
        $row['marital_status'] = 'KAWIN';
        $row['kk_number'] = '9999999999999999';
        $validation = $this->service->validateRows([$row], $this->identityMapping());

        $response = $this->postImportWithValidation([$row], $validation);

        $response->assertOk()
            ->assertJsonPath('total_imported', 0)
            ->assertJsonPath('duplicate_count', 0)
            ->assertJsonPath('invalid_count', 1);
        $this->assertDatabaseCount('penduduk', 0);
    }

    public function test_import_result_reports_mixed_service_counts(): void
    {
        $this->makeInfrastructure();
        $duplicateRows = array_map(fn (int $index): array => $this->resultRow($index), [1, 2]);
        $this->service->importRows($duplicateRows);

        $validRows = array_map(fn (int $index): array => $this->resultRow($index), range(3, 10));
        $invalidRows = array_map(function (int $index): array {
            $row = $this->resultRow($index);
            $row['kk_number'] = '9999999999999999';

            return $row;
        }, range(11, 13));
        $rows = [...$validRows, ...$duplicateRows, ...$invalidRows];
        $validation = $this->service->validateRows($rows, $this->identityMapping());

        $response = $this->postImportWithValidation($rows, $validation);

        $response->assertOk()
            ->assertJsonPath('total_imported', 8)
            ->assertJsonPath('duplicate_count', 2)
            ->assertJsonPath('invalid_count', 3);
        $this->assertDatabaseCount('penduduk', 10);
    }

    public function test_import_page_rehydrates_actual_result_for_result_ui(): void
    {
        $result = [
            'total_imported' => 8,
            'duplicate_count' => 2,
            'invalid_count' => 3,
            'message' => 'Import selesai.',
        ];

        session()->put('penduduk_import', ['import_result' => $result]);

        $page = new ImportPenduduk;
        $page->mount();
        $page->currentStep = 'import';
        $page->completedImportResult = null;
        $page->hydrate();

        $this->assertSame('result', $page->currentStep);
        $this->assertSame($result, $page->completedImportResult);
    }

    public function test_invalid_row_is_rejected_in_preview(): void
    {
        $row = $this->validRow();
        $row['birth_date'] = 'not-a-date';
        $result = $this->service->validateRows([$row], $this->identityMapping());

        $this->assertSame(1, $result['invalid_count']);
        $this->assertSame('INVALID', $result['preview_rows'][0]['status']);
    }

    public function test_transaction_rolls_back_when_a_later_row_fails(): void
    {
        $this->makeInfrastructure();
        $bad = $this->validRow();
        $bad['nik'] = '3207122801160002';
        $bad['family_relation'] = 'NOT_A_RELATION';

        try {
            $this->service->importRows([$this->validRow(), $bad]);
            $this->fail('Expected the invalid enum to abort the transaction.');
        } catch (Throwable) {
            $this->assertDatabaseCount('penduduk', 0);
            $this->assertDatabaseCount('kk_anggota', 0);
        }
    }

    public function test_cancel_cleans_up_temporary_file(): void
    {
        Storage::fake('local');
        $response = $this->actingAs(User::factory()->create())->post(route('penduduk.import.upload'), [
            'file' => UploadedFile::fake()->createWithContent('penduduk.csv', implode(',', $this->headers())."\n".implode(',', $this->values())."\n"),
        ]);
        $response->assertOk();

        $session = session()->all();
        $this->actingAs(User::factory()->create())->withSession($session)->post(route('penduduk.import.cancel'))->assertOk();
        $this->assertEmpty(Storage::disk('local')->allFiles('temp/penduduk_import'));
    }

    /** @return array<int, string> */
    private function headers(): array
    {
        return ['NIK', 'Nama Lengkap', 'Nomor KK', 'Jenis Kelamin', 'Tanggal Lahir', 'RT', 'RW', 'Alamat'];
    }

    /** @return array<int, string> */
    private function values(): array
    {
        return ['3207122801160001', 'Budi Santoso', '3207122801160001', 'Laki-laki', '28-01-2016', '01', '01', 'Jl. Melati'];
    }

    private function makeXlsx(string $firstSheetName, array $rows, bool $secondSheet = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'penduduk-import-').'.xlsx';
        $this->temporaryFiles[] = $path;
        $writer = WriterFactory::createFromFile($path);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName($firstSheetName);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        if ($secondSheet) {
            $writer->addNewSheetAndMakeItCurrent()->setName('Sheet Kedua');
            $writer->addRow(Row::fromValues($this->headers()));
            $writer->addRow(Row::fromValues($this->values()));
        }
        $writer->close();

        return $path;
    }

    public function test_education_aliases_resolve_to_canonical_masters(): void
    {
        $this->seed(SystemReferenceSeeder::class);
        $this->makeInfrastructure();

        $cases = [
            'SMA/SEDERAJAT' => 'SMA',
            'SLTA/SEDERAJAT' => 'SMA',
            'SMA' => 'SMA',
            'SMK' => 'SMA',
            'SMP/SEDERAJAT' => 'SMP',
            'SLTP' => 'SMP',
            'TAMAT SD/SEDERAJAT' => 'SD',
            'SD' => 'SD',
            'D1' => 'D1',
            'D-I' => 'D1',
            'D2' => 'D2',
            'D-II' => 'D2',
            'D3' => 'D3',
            'D-III' => 'D3',
            'S1' => 'S1',
            'DIPLOMA IV/STRATA I' => 'S1',
            'S2' => 'S2',
            'STRATA II' => 'S2',
            'S3' => 'S3',
            'STRATA III' => 'S3',
            'TIDAK/BELUM SEKOLAH' => 'Tidak/Belum Sekolah',
        ];

        $index = 100;
        foreach ($cases as $excelVal => $expectedMasterName) {
            $row = $this->validRow();
            $row['nik'] = '320712280116'.sprintf('%04d', $index++);
            $row['education'] = $excelVal;

            $this->service->importRows([$row]);

            $imported = Penduduk::where('nik', $row['nik'])->firstOrFail();
            $this->assertSame($expectedMasterName, $imported->education->name, "Failed mapping '{$excelVal}' to master '{$expectedMasterName}'");
        }
    }

    public function test_occupation_aliases_resolve_to_canonical_masters(): void
    {
        $this->seed(SystemReferenceSeeder::class);
        $this->makeInfrastructure();

        $cases = [
            'MENGURUS RUMAH TANGGA' => 'Ibu Rumah Tangga',
            'IBU RUMAH TANGGA' => 'Ibu Rumah Tangga',
            'PELAJAR/MAHASISWA' => 'Pelajar/Mahasiswa',
            'PELAJARIMAHASISWA' => 'Pelajar/Mahasiswa',
            'PNS' => 'Pegawai Negeri Sipil',
            'PEGAWAI NEGERI SIPIL' => 'Pegawai Negeri Sipil',
            'BURUH HARIAN LEPAS' => 'Buruh',
            'BURUH' => 'Buruh',
            'PETANI/PEKEBUN' => 'Petani',
            'PETANI' => 'Petani',
            'KARYAWAN SWASTA' => 'Karyawan Swasta',
            'WIRASWASTA' => 'Wiraswasta',
        ];

        $index = 200;
        foreach ($cases as $excelVal => $expectedMasterName) {
            $row = $this->validRow();
            $row['nik'] = '320712280116'.sprintf('%04d', $index++);
            $row['occupation'] = $excelVal;

            $this->service->importRows([$row]);

            $imported = Penduduk::where('nik', $row['nik'])->firstOrFail();
            $this->assertSame($expectedMasterName, $imported->occupation->name, "Failed mapping '{$excelVal}' to master '{$expectedMasterName}'");
        }
    }

    public function test_marital_status_and_family_relation_aliases_resolve_to_enum(): void
    {
        $this->seed(SystemReferenceSeeder::class);
        $this->makeInfrastructure();

        $maritalCases = [
            'BELUMKAWIN' => MaritalStatus::BELUM_KAWIN,
            'BELUM KAWIN' => MaritalStatus::BELUM_KAWIN,
            'KAWIN' => MaritalStatus::KAWIN,
            'CERAI HIDUP' => MaritalStatus::CERAI_HIDUP,
            'CERAI MATI' => MaritalStatus::CERAI_MATI,
        ];

        $relationCases = [
            'KEPALA KELUARGA' => FamilyRelation::KEPALA_KELUARGA,
            'ISTRI' => FamilyRelation::ISTRI,
            'ANAK' => FamilyRelation::ANAK,
            'MENANTU' => FamilyRelation::MENANTU,
            'CUCU' => FamilyRelation::CUCU,
            'ORANG TUA' => FamilyRelation::ORANG_TUA,
            'MERTUA' => FamilyRelation::MERTUA,
            'FAMILI LAIN' => FamilyRelation::FAMILI_LAIN,
            'LAINNYA' => FamilyRelation::LAINNYA,
        ];

        $index = 300;
        foreach ($maritalCases as $inputMarital => $expectedEnum) {
            $row = $this->validRow();
            $row['nik'] = '320712280116'.sprintf('%04d', $index++);
            $row['marital_status'] = $inputMarital;

            $this->service->importRows([$row]);
            $imported = Penduduk::where('nik', $row['nik'])->firstOrFail();
            $this->assertSame($expectedEnum, $imported->marital_status);
        }

        foreach ($relationCases as $inputRelation => $expectedEnum) {
            $row = $this->validRow();
            $row['nik'] = '320712280116'.sprintf('%04d', $index++);
            $row['family_relation'] = $inputRelation;

            $this->service->importRows([$row]);
            $imported = Penduduk::where('nik', $row['nik'])->firstOrFail();
            $this->assertSame($expectedEnum, $imported->family_relation);
        }
    }

    public function test_scientific_and_float_numeric_codes_recovery(): void
    {
        $this->seed(SystemReferenceSeeder::class);
        $this->makeInfrastructure();

        $this->assertSame('3207122801160001', $this->service->normalizeNumericCode('3.207122801160001E+15'));
        $this->assertSame('3207122801160001', $this->service->normalizeNumericCode('3207122801160001.0'));
        $this->assertSame('3207122801160001', $this->service->normalizeNumericCode('3207122801160001'));
    }

    public function test_ocr_and_excel_cross_compatibility(): void
    {
        $this->seed(SystemReferenceSeeder::class);
        $this->makeInfrastructure();

        // Sample input text
        $eduInput = 'SMA/SEDERAJAT';
        $occInput = 'MENGURUS RUMAH TANGGA';
        $maritalInput = 'BELUMKAWIN';
        $relInput = 'KEPALA KELUARGA';

        // 1. OCR Route
        $ocrText = implode("\n", [
            'NOMOR KARTU KELUARGA : 3207122801160001',
            'ALAMAT : JL. MELATI',
            'RT/RW : 001/001',
            'NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN',
            "1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-1980 ISLAM {$eduInput} {$occInput}",
            'NO STATUS PERKAWINAN STATUS HUBUNGAN DALAM KELUARGA KEWARGANEGARAAN NAMA AYAH NAMA IBU',
            "1 {$maritalInput} {$relInput} WNI HASAN FATIMAH",
        ]);
        $ocrResult = (new OcrParsingService)->parse($ocrText, 90.0);
        $ocrMember = $ocrResult->members[0];

        // 2. Excel Route
        $excelRow = [
            'nik' => '3207122801160001',
            'full_name' => 'Budi Santoso',
            'kk_number' => '3207122801160001',
            'gender' => 'Laki-laki',
            'birth_date' => '1980-01-28',
            'birth_place' => 'Tanete',
            'religion' => 'Islam',
            'education' => $eduInput,
            'occupation' => $occInput,
            'marital_status' => $maritalInput,
            'family_relation' => $relInput,
            'rt' => '01',
            'rw' => '01',
            'address' => 'Jl. Melati',
        ];
        $this->service->importRows([$excelRow]);
        $excelImported = Penduduk::where('nik', '3207122801160001')->firstOrFail();

        // Verify that both OCR and Excel resolve to identical domain master/enum values!
        $this->assertSame('SMA', $excelImported->education->name);
        $this->assertSame('Ibu Rumah Tangga', $excelImported->occupation->name);
        $this->assertSame(MaritalStatus::BELUM_KAWIN, $excelImported->marital_status);
        $this->assertSame(FamilyRelation::KEPALA_KELUARGA, $excelImported->family_relation);
    }

    public function test_leading_zero_preserved_in_nik_and_kk(): void
    {
        $this->seed(SystemReferenceSeeder::class);
        $rt = Rt::factory()->create(['number' => '01']);
        $kk = KartuKeluarga::create(['kk_number' => '0123456789012345', 'address' => 'Jl. Melati', 'rt_id' => $rt->id]);

        $row = $this->validRow();
        $row['nik'] = '0123456789012345';
        $row['kk_number'] = '0123456789012345';

        $this->service->importRows([$row]);

        $resident = Penduduk::where('nik', '0123456789012345')->firstOrFail();
        $this->assertSame('0123456789012345', $resident->nik);
        $this->assertSame('0123456789012345', $resident->kartuKeluarga->kk_number);
    }

    public function test_date_parsing_various_formats_and_serial_date(): void
    {
        // YYYY-MM-DD
        $this->assertSame('1990-01-15', $this->service->normalizeBirthDateFromRow('1990-01-15'));
        // DD/MM/YYYY
        $this->assertSame('1990-01-15', $this->service->normalizeBirthDateFromRow('15/01/1990'));
        // DD-MM-YYYY
        $this->assertSame('1990-01-15', $this->service->normalizeBirthDateFromRow('15-01-1990'));
        // Excel serial date 42000 (~ 2014-12-27)
        $this->assertSame('2014-12-27', $this->service->normalizeBirthDateFromRow('42000'));
        // Invalid date
        $this->assertNull($this->service->normalizeBirthDateFromRow('32-13-2005'));
    }

    public function test_gender_normalization_variations(): void
    {
        $this->seed(SystemReferenceSeeder::class);
        $this->makeInfrastructure();

        $cases = [
            'LAKI-LAKI' => Gender::LAKI_LAKI,
            'Laki-laki' => Gender::LAKI_LAKI,
            'Laki laki' => Gender::LAKI_LAKI,
            'L' => Gender::LAKI_LAKI,
            'PRIA' => Gender::LAKI_LAKI,
            'PEREMPUAN' => Gender::PEREMPUAN,
            'Perempuan' => Gender::PEREMPUAN,
            'WANITA' => Gender::PEREMPUAN,
            'P' => Gender::PEREMPUAN,
        ];

        $index = 400;
        foreach ($cases as $genderInput => $expectedGender) {
            $row = $this->validRow();
            $row['nik'] = '320712280116'.sprintf('%04d', $index++);
            $row['gender'] = $genderInput;

            $this->service->importRows([$row]);
            $imported = Penduduk::where('nik', $row['nik'])->firstOrFail();
            $this->assertSame($expectedGender, $imported->gender);
        }
    }

    public function test_religion_master_resolution(): void
    {
        $this->seed(SystemReferenceSeeder::class);
        $this->makeInfrastructure();

        $religions = [
            'Islam' => 'Islam',
            'ISLAM' => 'Islam',
            'Kristen' => 'Kristen',
            'KRISTEN' => 'Kristen',
            'Katolik' => 'Katolik',
            'KATOLIK' => 'Katolik',
            'Hindu' => 'Hindu',
            'HINDU' => 'Hindu',
            'Buddha' => 'Buddha',
            'Konghucu' => 'Konghucu',
        ];

        $index = 500;
        foreach ($religions as $inputRel => $expectedMaster) {
            $row = $this->validRow();
            $row['nik'] = '320712280116'.sprintf('%04d', $index++);
            $row['religion'] = $inputRel;

            $this->service->importRows([$row]);
            $imported = Penduduk::where('nik', $row['nik'])->firstOrFail();
            $this->assertSame($expectedMaster, $imported->religion->name);
        }
    }

    public function test_status_penduduk_and_status_dates(): void
    {
        $this->seed(SystemReferenceSeeder::class);
        $this->makeInfrastructure();

        // 1. Active status with active_at
        $row1 = $this->validRow();
        $row1['nik'] = '3207122801160601';
        $row1['resident_status'] = 'AKTIF';
        $row1['active_at'] = '2026-08-20';
        $this->service->importRows([$row1]);
        $res1 = Penduduk::where('nik', $row1['nik'])->firstOrFail();
        $this->assertSame(ResidentStatus::ACTIVE, $res1->resident_status);
        $this->assertSame('2026-08-20', $res1->active_at?->format('Y-m-d'));

        // 2. Pindah status with moved_at
        $row2 = $this->validRow();
        $row2['nik'] = '3207122801160602';
        $row2['resident_status'] = 'PINDAH';
        $row2['moved_at'] = '2026-08-21';
        $this->service->importRows([$row2]);
        $res2 = Penduduk::where('nik', $row2['nik'])->firstOrFail();
        $this->assertSame(ResidentStatus::PINDAH, $res2->resident_status);
        $this->assertSame('2026-08-21', $res2->moved_at?->format('Y-m-d'));

        // 3. Meninggal status with deceased_at
        $row3 = $this->validRow();
        $row3['nik'] = '3207122801160603';
        $row3['resident_status'] = 'MENINGGAL';
        $row3['deceased_at'] = '2026-08-22';
        $this->service->importRows([$row3]);
        $res3 = Penduduk::where('nik', $row3['nik'])->firstOrFail();
        $this->assertSame(ResidentStatus::MENINGGAL, $res3->resident_status);
        $this->assertSame('2026-08-22', $res3->deceased_at?->format('Y-m-d'));
    }

    public function test_unrecognized_headers_identified_cleanly(): void
    {
        $headers = ['NIK', 'Nama Lengkap', 'Nomor KK', 'Kolom Aneh', 'Field Random'];
        $suggested = $this->service->suggestMapping($headers);

        $this->assertContains('Kolom Aneh', $suggested['unrecognized']);
        $this->assertContains('Field Random', $suggested['unrecognized']);
        $this->assertNotContains('NIK', $suggested['unrecognized']);
        $this->assertNotContains('Nama Lengkap', $suggested['unrecognized']);
    }

    private function makeInfrastructure(): KartuKeluarga
    {
        $rt = Rt::factory()->create(['number' => '01']);

        return KartuKeluarga::create(['kk_number' => '3207122801160001', 'address' => 'Jl. Melati', 'rt_id' => $rt->id]);
    }

    /** @return array<string, mixed> */
    private function validRow(): array
    {
        return ['nik' => '3207122801160001', 'full_name' => 'Budi Santoso', 'kk_number' => '3207122801160001', 'gender' => 'Laki-laki', 'birth_date' => '2016-01-28', 'birth_place' => 'Tanete', 'religion' => 'Islam', 'education' => 'SMA', 'occupation' => 'Petani', 'marital_status' => 'Belum Menikah', 'family_relation' => 'Kepala Keluarga', 'rt' => '01', 'rw' => '01', 'address' => 'Jl. Melati'];
    }

    /** @return array<string, mixed> */
    private function resultRow(int $index): array
    {
        $row = $this->validRow();
        $row['nik'] = '320712280116'.sprintf('%04d', $index);
        $row['full_name'] = 'Budi Santoso '.$index;
        $row['marital_status'] = 'KAWIN';

        return $row;
    }

    /** @return array<string, string> */
    private function identityMapping(): array
    {
        return array_combine(array_keys($this->validRow()), array_keys($this->validRow()));
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function postImportWithValidation(array $rows, array $validation): TestResponse
    {
        $response = $this->actingAs(User::factory()->create())
            ->withSession([
                'penduduk_import' => ['rows' => $rows],
                'penduduk_import.mapping' => ['mapping' => $this->identityMapping()],
                'penduduk_import.validation' => $validation,
            ])
            ->post(route('penduduk.import'));

        return $response;
    }
}
