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

class KartuKeluargaOcrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed lookups for religion, education, occupation
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

    public function test_sample_kk_ocr_extraction_matches_ground_truth_100_percent(): void
    {
        $fixturePath = base_path('tests/Fixtures/sample_kk.png');
        $this->assertFileExists($fixturePath, 'Sample KK fixture must exist.');

        $engine = app(TesseractOcrEngine::class);
        $ocrResult = $engine->run($fixturePath);

        $this->assertNotEmpty($ocrResult->rawText);
        $this->assertGreaterThan(70.0, $ocrResult->confidence);

        $parser = app(OcrParsingService::class);
        $parsed = $parser->parse($ocrResult->rawText, $ocrResult->confidence);

        $this->assertInstanceOf(ParsedOcrResult::class, $parsed);
        $this->assertSame('7304012304990001', $parsed->kkNumber);
        $this->assertSame('JL. POROS PARE-PARE NO. 45', $parsed->address);
        $this->assertSame('001', $parsed->rt);
        $this->assertSame('002', $parsed->rw);
        $this->assertNull($parsed->lingkungan);
        $this->assertCount(4, $parsed->members);
        $this->assertTrue($parsed->isValid());
        $this->assertEmpty($parsed->validationErrors);

        // Ground Truth definitions
        $groundTruth = [
            1 => [
                'nik' => '7304010101750001',
                'nama' => 'MUHAMMAD AMIN',
                'gender' => 'LAKI_LAKI',
                'birth_place' => 'BARRU',
                'birth_date' => '1975-01-01',
                'religion' => 'ISLAM',
                'education' => 'SLTA/SEDERAJAT',
                'occupation' => 'PETANI',
                'marital_status' => 'KAWIN',
                'family_relation' => 'KEPALA_KELUARGA',
                'ayah' => 'ABDULLAH',
                'ibu' => 'FATIMAH',
            ],
            2 => [
                'nik' => '7304014506800002',
                'nama' => 'NURHAYATI',
                'gender' => 'PEREMPUAN',
                'birth_place' => 'BARRU',
                'birth_date' => '1980-06-05',
                'religion' => 'ISLAM',
                'education' => 'SLTA/SEDERAJAT',
                'occupation' => 'IBU RUMAH TANGGA',
                'marital_status' => 'KAWIN',
                'family_relation' => 'ISTRI',
                'ayah' => 'HASAN',
                'ibu' => 'MARIAM',
            ],
            3 => [
                'nik' => '7304011208050003',
                'nama' => 'AHMAD FAUZI',
                'gender' => 'LAKI_LAKI',
                'birth_place' => 'BARRU',
                'birth_date' => '2005-08-12',
                'religion' => 'ISLAM',
                'education' => 'SLTA/SEDERAJAT',
                'occupation' => 'PELAJAR/MAHASISWA',
                'marital_status' => 'BELUM_KAWIN',
                'family_relation' => 'ANAK',
                'ayah' => 'MUHAMMAD AMIN',
                'ibu' => 'NURHAYATI',
            ],
            4 => [
                'nik' => '7304015011100004',
                'nama' => 'SITIAISYAH',
                'gender' => 'PEREMPUAN',
                'birth_place' => 'BARRU',
                'birth_date' => '2010-11-10',
                'religion' => 'ISLAM',
                'education' => 'SLTP/SEDERAJAT',
                'occupation' => 'PELAJAR/MAHASISWA',
                'marital_status' => 'BELUM_KAWIN',
                'family_relation' => 'ANAK',
                'ayah' => 'MUHAMMAD AMIN',
                'ibu' => 'NURHAYATI',
            ],
        ];

        foreach ($parsed->members as $idx => $member) {
            $ord = $idx + 1;
            $gt = $groundTruth[$ord];

            $this->assertSame($gt['nik'], $member->nik, "Anggota #{$ord} NIK mismatch");
            $this->assertSame($gt['nama'], $member->nama, "Anggota #{$ord} Nama mismatch");
            $this->assertSame($gt['gender'], $member->gender, "Anggota #{$ord} Gender mismatch");
            $this->assertSame($gt['birth_place'], $member->birthPlace, "Anggota #{$ord} Tempat Lahir mismatch");
            $this->assertSame($gt['birth_date'], $member->birthDate, "Anggota #{$ord} Tanggal Lahir mismatch");
            $this->assertSame($gt['religion'], $member->religion, "Anggota #{$ord} Agama mismatch");
            $this->assertSame($gt['education'], $member->education, "Anggota #{$ord} Pendidikan mismatch");
            $this->assertSame($gt['occupation'], $member->occupation, "Anggota #{$ord} Pekerjaan mismatch");
            $this->assertSame($gt['marital_status'], $member->maritalStatus, "Anggota #{$ord} Status Kawin mismatch");
            $this->assertSame($gt['family_relation'], $member->familyRelation, "Anggota #{$ord} Hubungan Keluarga mismatch");
            $this->assertSame($gt['ayah'], $member->ayah, "Anggota #{$ord} Nama Ayah mismatch");
            $this->assertSame($gt['ibu'], $member->ibu, "Anggota #{$ord} Nama Ibu mismatch");
        }
    }
}