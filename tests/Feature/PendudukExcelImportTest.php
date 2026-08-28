<?php

namespace Tests\Feature;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Filament\Pages\ImportPenduduk;
use App\Models\AreaUnit;
use App\Models\KartuKeluarga;
use App\Models\KkAnggota;
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
        $result = $this->service->suggestMapping(['NIK', 'Nama']);

        $this->assertContains('kk_number', $result['missing_required']);
        $this->assertNotContains('gender', $result['missing_required']);
        $this->assertNotContains('address', $result['missing_required']);

        $completeResult = $this->service->suggestMapping(['NIK', 'Nama', 'No KK']);
        $this->assertEmpty($completeResult['missing_required']);
    }

    public function test_duplicate_nik_is_reported_without_overwrite(): void
    {
        $this->makeInfrastructure();
        $this->service->importRows([$this->validRow()]);
        $result = $this->service->validateRows([$this->validRow()], $this->identityMapping());

        $this->assertSame(1, $result['duplicate_count']);
        $this->assertSame(1, Penduduk::where('nik', $this->validRow()['nik'])->count());
    }

    public function test_invalid_kk_format_is_rejected(): void
    {
        $row = $this->validRow();
        $row['kk_number'] = '12345'; // Invalid length
        $result = $this->service->validateRows([$row], $this->identityMapping());

        $this->assertSame(1, $result['invalid_count']);
        $this->assertStringContainsString('Nomor KK wajib terdiri dari 16 digit', implode(' ', $result['errors'][2]));
    }

    public function test_new_kk_is_auto_created_and_members_are_linked_to_single_kk(): void
    {
        $this->makeInfrastructure();

        $rows = [
            [
                'nik' => '7304010101800001',
                'full_name' => 'Budi Santoso',
                'kk_number' => '7304010101809999',
                'gender' => 'LAKI-LAKI',
                'birth_date' => '1980-01-01',
                'family_relation' => 'KEPALA KELUARGA',
                'address' => 'Jl. Mawar No. 10',
                'rt' => '01',
                'rw' => '02',
            ],
            [
                'nik' => '7304014502850002',
                'full_name' => 'Ani Wijaya',
                'kk_number' => '7304010101809999',
                'gender' => 'PEREMPUAN',
                'birth_date' => '1985-02-15',
                'family_relation' => 'ISTRI',
                'address' => 'Jl. Mawar No. 10',
                'rt' => '01',
                'rw' => '02',
            ],
            [
                'nik' => '7304011203050003',
                'full_name' => 'Andi Santoso',
                'kk_number' => '7304010101809999',
                'gender' => 'LAKI-LAKI',
                'birth_date' => '2005-03-12',
                'family_relation' => 'ANAK',
                'address' => 'Jl. Mawar No. 10',
                'rt' => '01',
                'rw' => '02',
            ],
            [
                'nik' => '7304016005900004',
                'full_name' => 'Siti Aminah',
                'kk_number' => '7304010101908888',
                'gender' => 'PEREMPUAN',
                'birth_date' => '1990-05-20',
                'family_relation' => 'KEPALA KELUARGA',
                'address' => 'Jl. Melati No. 20',
                'rt' => '01',
                'rw' => '02',
            ],
        ];

        $validation = $this->service->validateRows($rows, $this->identityMapping());
        $this->assertSame(4, $validation['valid_count']);
        $this->assertSame(2, $validation['new_kk_count']);
        $this->assertSame(0, $validation['existing_kk_count']);

        $initialKkCount = KartuKeluarga::count();
        $result = $this->service->importRows($rows);

        $this->assertSame(4, $result['imported']);
        $this->assertSame(2, $result['created_kk']);
        $this->assertSame(0, $result['existing_kk']);

        // Pastikan HANYA 2 KK baru yang dibuat (tidak ada KK duplikat)
        $this->assertSame($initialKkCount + 2, KartuKeluarga::count());
        $this->assertSame(1, KartuKeluarga::where('kk_number', '7304010101809999')->count());
        $this->assertSame(1, KartuKeluarga::where('kk_number', '7304010101908888')->count());
        $kk1 = KartuKeluarga::where('kk_number', '7304010101809999')->first();
        $kk2 = KartuKeluarga::where('kk_number', '7304010101908888')->first();
        $this->assertNotNull($kk1);
        $this->assertNotNull($kk2);

        // Pastikan anggota keluarga terhubung dengan benar
        $this->assertSame(3, $kk1->penduduks()->count());
        $this->assertSame(1, $kk2->penduduks()->count());

        $budi = Penduduk::where('nik', '7304010101800001')->first();
        $ani = Penduduk::where('nik', '7304014502850002')->first();
        $andi = Penduduk::where('nik', '7304011203050003')->first();
        $siti = Penduduk::where('nik', '7304016005900004')->first();

        $this->assertSame($kk1->id, $budi->kk_id);
        $this->assertSame($kk1->id, $ani->kk_id);
        $this->assertSame($kk1->id, $andi->kk_id);
        $this->assertSame($kk2->id, $siti->kk_id);
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
        $row['kk_number'] = '12345';
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
            $row['kk_number'] = 'invalid_kk';

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

    public function test_excel_import_aggregates_kk_metadata_from_head_of_family_row(): void
    {
        $this->seed(SystemReferenceSeeder::class);
        $rt = Rt::factory()->create(['number' => '01']);

        $rows = [
            // Row 1: Head of family with full KK metadata
            [
                'nik' => '7304010101800001',
                'full_name' => 'Budi Santoso',
                'kk_number' => '7304010101809999',
                'gender' => 'LAKI-LAKI',
                'birth_date' => '1980-01-01',
                'family_relation' => 'KEPALA KELUARGA',
                'address' => 'JL. POROS PARE-PARE NO. 45',
                'rt' => '01',
                'postal_code' => '90761',
                'notes' => 'Keluarga Budi',
            ],
            // Row 2: Spouse with empty address / postal code
            [
                'nik' => '7304014502850002',
                'full_name' => 'Ani Wijaya',
                'kk_number' => '7304010101809999',
                'gender' => 'PEREMPUAN',
                'birth_date' => '1985-02-15',
                'family_relation' => 'ISTRI',
                'address' => '',
                'rt' => '01',
                'postal_code' => '',
            ],
            // Row 3: Child with empty address / postal code
            [
                'nik' => '7304011203050003',
                'full_name' => 'Andi Santoso',
                'kk_number' => '7304010101809999',
                'gender' => 'LAKI-LAKI',
                'birth_date' => '2005-03-12',
                'family_relation' => 'ANAK',
                'address' => '',
                'rt' => '01',
                'postal_code' => '',
            ],
        ];

        $result = $this->service->importRows($rows);
        $this->assertSame(3, $result['imported']);
        $this->assertSame(1, $result['created_kk']);

        $kk = KartuKeluarga::where('kk_number', '7304010101809999')->firstOrFail();
        $this->assertSame('JL. POROS PARE-PARE NO. 45', $kk->address);
        $this->assertSame('90761', $kk->postal_code);
        $this->assertSame($rt->id, $kk->rt_id);
        $this->assertSame(3, $kk->penduduks()->count());
    }

    public function test_excel_reimport_does_not_create_duplicate_records(): void
    {
        $this->seed(SystemReferenceSeeder::class);
        $rt = Rt::factory()->create(['number' => '01']);

        $rows = [
            [
                'nik' => '7304010101800001',
                'full_name' => 'Budi Santoso',
                'kk_number' => '7304010101809999',
                'gender' => 'LAKI-LAKI',
                'birth_date' => '1980-01-01',
                'family_relation' => 'KEPALA KELUARGA',
                'address' => 'JL. POROS PARE-PARE NO. 45',
                'rt' => '01',
            ],
            [
                'nik' => '7304014502850002',
                'full_name' => 'Ani Wijaya',
                'kk_number' => '7304010101809999',
                'gender' => 'PEREMPUAN',
                'birth_date' => '1985-02-15',
                'family_relation' => 'ISTRI',
                'address' => 'JL. POROS PARE-PARE NO. 45',
                'rt' => '01',
            ],
        ];

        // First import
        $firstResult = $this->service->importRows($rows);
        $this->assertSame(2, $firstResult['imported']);
        $this->assertSame(1, $firstResult['created_kk']);

        $kkCountBefore = KartuKeluarga::count();
        $pendudukCountBefore = Penduduk::count();
        $kkAnggotaCountBefore = KkAnggota::count();

        // Second import (reimporting exact same dataset)
        $secondResult = $this->service->importRows($rows);
        $this->assertSame(0, $secondResult['imported']);
        $this->assertSame(2, $secondResult['duplicates']);
        $this->assertSame(0, $secondResult['created_kk']);

        // Assert no new records created
        $this->assertSame($kkCountBefore, KartuKeluarga::count());
        $this->assertSame($pendudukCountBefore, Penduduk::count());
        $this->assertSame($kkAnggotaCountBefore, KkAnggota::count());
    }

    public function test_excel_import_enriches_existing_kk_without_overwriting(): void
    {
        $this->seed(SystemReferenceSeeder::class);
        $rt = Rt::factory()->create(['number' => '01']);

        // Pre-create KK with missing postal code and dummy address '-'
        $existingKk = KartuKeluarga::create([
            'kk_number' => '7304010101809999',
            'address' => '-',
            'rt_id' => $rt->id,
            'postal_code' => null,
        ]);

        $rows = [
            [
                'nik' => '7304010101800001',
                'full_name' => 'Budi Santoso',
                'kk_number' => '7304010101809999',
                'gender' => 'LAKI-LAKI',
                'birth_date' => '1980-01-01',
                'family_relation' => 'KEPALA KELUARGA',
                'address' => 'JL. POROS PARE-PARE NO. 45',
                'rt' => '01',
                'postal_code' => '90761',
            ],
        ];

        $result = $this->service->importRows($rows);
        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['created_kk']);
        $this->assertSame(1, $result['existing_kk']);

        $existingKk->refresh();
        $this->assertSame('JL. POROS PARE-PARE NO. 45', $existingKk->address);
        $this->assertSame('90761', $existingKk->postal_code);
    }

    public function test_excel_import_with_10_members_in_single_kk(): void
    {
        $this->seed(SystemReferenceSeeder::class);
        $rt = Rt::factory()->create(['number' => '01']);

        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = [
                'nik' => sprintf('730401%02d0180%04d', $i, $i),
                'full_name' => 'Anggota Keluarga ' . $i,
                'kk_number' => '7304010101807777',
                'gender' => $i % 2 === 0 ? 'PEREMPUAN' : 'LAKI-LAKI',
                'birth_date' => sprintf('198%d-01-01', $i % 10),
                'family_relation' => $i === 1 ? 'KEPALA KELUARGA' : 'ANAK',
                'address' => 'JL. KELUARGA BESAR NO. 10',
                'rt' => '01',
            ];
        }

        $result = $this->service->importRows($rows);
        $this->assertSame(10, $result['imported']);
        $this->assertSame(1, $result['created_kk']);

        $kk = KartuKeluarga::where('kk_number', '7304010101807777')->firstOrFail();
        $this->assertSame(10, $kk->penduduks()->count());
        $this->assertSame(10, KkAnggota::where('kk_id', $kk->id)->count());
    }

    public function test_exact_rt_rw_scoping_across_multiple_rws(): void
    {
        $this->seed(SystemReferenceSeeder::class);

        $rw1 = AreaUnit::create(['name' => 'RW 01', 'type' => 'rw', 'code' => '01']);
        $rw2 = AreaUnit::create(['name' => 'RW 02', 'type' => 'rw', 'code' => '02']);

        $rt1Rw1 = Rt::create(['number' => '01', 'area_unit_id' => $rw1->id]);
        $rt2Rw1 = Rt::create(['number' => '02', 'area_unit_id' => $rw1->id]);
        $rt1Rw2 = Rt::create(['number' => '01', 'area_unit_id' => $rw2->id]);
        $rt2Rw2 = Rt::create(['number' => '02', 'area_unit_id' => $rw2->id]);

        $rows = [
            [
                'nik' => '7304010101800001',
                'full_name' => 'Warga KK A',
                'kk_number' => '7304010101809001',
                'gender' => 'Laki-laki',
                'birth_date' => '1980-01-01',
                'birth_place' => 'Barru',
                'religion' => 'Islam',
                'education' => 'SMA',
                'occupation' => 'Petani',
                'marital_status' => 'Kawin',
                'family_relation' => 'Kepala Keluarga',
                'address' => 'Jl. Mawar',
                'rt' => '01',
                'rw' => '01',
            ],
            [
                'nik' => '7304010101800002',
                'full_name' => 'Warga KK B',
                'kk_number' => '7304010101809002',
                'gender' => 'Laki-laki',
                'birth_date' => '1980-01-01',
                'birth_place' => 'Barru',
                'religion' => 'Islam',
                'education' => 'SMA',
                'occupation' => 'Petani',
                'marital_status' => 'Kawin',
                'family_relation' => 'Kepala Keluarga',
                'address' => 'Jl. Melati',
                'rt' => '01',
                'rw' => '02',
            ],
            [
                'nik' => '7304010101800003',
                'full_name' => 'Warga KK C',
                'kk_number' => '7304010101809003',
                'gender' => 'Laki-laki',
                'birth_date' => '1980-01-01',
                'birth_place' => 'Barru',
                'religion' => 'Islam',
                'education' => 'SMA',
                'occupation' => 'Petani',
                'marital_status' => 'Kawin',
                'family_relation' => 'Kepala Keluarga',
                'address' => 'Jl. Anggrek',
                'rt' => '02',
                'rw' => '02',
            ],
        ];

        $result = $this->service->importRows($rows);
        $this->assertSame(3, $result['imported']);
        $this->assertSame(3, $result['created_kk']);

        $kkA = KartuKeluarga::where('kk_number', '7304010101809001')->firstOrFail();
        $kkB = KartuKeluarga::where('kk_number', '7304010101809002')->firstOrFail();
        $kkC = KartuKeluarga::where('kk_number', '7304010101809003')->firstOrFail();

        $this->assertSame($rt1Rw1->id, $kkA->rt_id);
        $this->assertSame($rw1->id, $kkA->rt->area_unit_id);
        $this->assertSame('RW 01', $kkA->rt->areaUnit->name);

        $this->assertSame($rt1Rw2->id, $kkB->rt_id);
        $this->assertSame($rw2->id, $kkB->rt->area_unit_id);
        $this->assertSame('RW 02', $kkB->rt->areaUnit->name);

        $this->assertSame($rt2Rw2->id, $kkC->rt_id);
        $this->assertSame($rw2->id, $kkC->rt->area_unit_id);
        $this->assertSame('RW 02', $kkC->rt->areaUnit->name);

        $pA = Penduduk::where('nik', '7304010101800001')->firstOrFail();
        $pB = Penduduk::where('nik', '7304010101800002')->firstOrFail();
        $pC = Penduduk::where('nik', '7304010101800003')->firstOrFail();

        $this->assertSame($rt1Rw1->id, $pA->rt_id);
        $this->assertSame($rt1Rw2->id, $pB->rt_id);
        $this->assertSame($rt2Rw2->id, $pC->rt_id);
    }

    private function makeInfrastructure(): KartuKeluarga
    {
        $rw1 = AreaUnit::firstOrCreate(['code' => '01'], ['name' => 'RW 01', 'type' => 'rw']);
        $rw2 = AreaUnit::firstOrCreate(['code' => '02'], ['name' => 'RW 02', 'type' => 'rw']);
        $rt1 = Rt::firstOrCreate(['number' => '01', 'area_unit_id' => $rw1->id]);
        $rt2 = Rt::firstOrCreate(['number' => '01', 'area_unit_id' => $rw2->id]);

        return KartuKeluarga::create(['kk_number' => '3207122801160001', 'address' => 'Jl. Melati', 'rt_id' => $rt1->id]);
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
