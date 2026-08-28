<?php

namespace Tests\Feature\Phase5;

use App\Services\OcrParsingService;
use App\Services\ParsedOcrResult;
use App\Services\ParsedResident;
use Tests\TestCase;

/**
 * Phase 5.5 — OCR parsing and mapping service.
 *
 * Proves OcrParsingService converts raw OCR text into the structured
 * ParsedOcrResult: KK header fields (nomor KK, alamat, RT, RW, lingkungan)
 * and member rows (nama, NIK, gender, tempat lahir, tanggal lahir, agama,
 * pendidikan, pekerjaan, status perkawinan, status hubungan keluarga) —
 * and that every failure mode (missing values, duplicated labels, malformed
 * OCR, low-confidence text, empty input) degrades gracefully without
 * throwing and without touching the database.
 */
class OcrParsingServiceTest extends TestCase
{
    /** Realistic KK scan output (Tesseract --psm 6 shape). */
    private const KK_TEXT = <<<'TXT'
NOMOR KARTU KELUARGA : 3207122801160001
NAMA KEPALA KELUARGA : BUDI SANTOSO
ALAMAT : JL. MELATI NO. 5
RT/RW : 001/004
KELURAHAN : TANETE
KECAMATAN : TANETE
KABUPATEN/KOTA : KAB. BOGOR
PROVINSI : JAWA BARAT
KODE POS : 16340

NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN STATUS PERKAWINAN STATUS HUBUNGAN DALAM KELUARGA
1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA
2 SITI AMINAH 3207124501010002 PEREMPUAN TANETE 05-04-2018 ISLAM SLTA/SEDERAJAT IBU RUMAH TANGGA KAWIN ISTRI
3 Andi Prasetyo 3207121503050003 LAKI-LAKI BOGOR 15-03-2005 ISLAM SMP PELAJAR/MAHASISWA BELUM KAWIN ANAK
TXT;

    private const TABLE_HEADER = 'NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN STATUS PERKAWINAN STATUS HUBUNGAN DALAM KELUARGA';

    public function test_valid_kk_text_parses_all_defined_fields(): void
    {
        $result = $this->parse(self::KK_TEXT, 92.5);

        $this->assertInstanceOf(ParsedOcrResult::class, $result);
        $this->assertFalse($result->isEmpty());
        $this->assertSame(92.5, $result->confidence);
        $this->assertFalse($result->lowConfidence);
        $this->assertSame([], $result->warnings);
        $this->assertSame([], $result->validationErrors);

        // KK-level fields (FR-OCR-02).
        $this->assertSame('3207122801160001', $result->kkNumber);
        $this->assertSame('JL. MELATI NO. 5', $result->address);
        $this->assertSame('001', $result->rt);
        $this->assertSame('004', $result->rw);
        $this->assertSame('16340', $result->postalCode);
        $this->assertNull($result->lingkungan);

        $this->assertCount(3, $result->members);
        $this->assertSame(3, $result->memberCount());

        $head = $result->members[0];
        $this->assertInstanceOf(ParsedResident::class, $head);
        $this->assertSame('BUDI SANTOSO', $head->nama);
        $this->assertSame('3207122801160001', $head->nik);
        $this->assertSame('LAKI_LAKI', $head->gender);
        $this->assertSame('TANETE', $head->birthPlace);
        $this->assertSame('2016-01-28', $head->birthDate);
        $this->assertSame('ISLAM', $head->religion);
        $this->assertSame('SLTA/SEDERAJAT', $head->education);
        $this->assertSame('BURUH', $head->occupation);
        $this->assertSame('KAWIN', $head->maritalStatus);
        $this->assertSame('KEPALA_KELUARGA', $head->familyRelation);
        $this->assertSame(92.5, $head->confidence);
        $this->assertFalse($head->lowConfidence);

        $spouse = $result->members[1];
        $this->assertSame('SITI AMINAH', $spouse->nama);
        $this->assertSame('3207124501010002', $spouse->nik);
        $this->assertSame('PEREMPUAN', $spouse->gender);
        $this->assertSame('2018-04-05', $spouse->birthDate);
        $this->assertSame('IBU RUMAH TANGGA', $spouse->occupation);
        $this->assertSame('KAWIN', $spouse->maritalStatus);
        $this->assertSame('ISTRI', $spouse->familyRelation);

        $child = $result->members[2];
        // Raw OCR casing is preserved for display fields.
        $this->assertSame('Andi Prasetyo', $child->nama);
        $this->assertSame('2005-03-15', $child->birthDate);
        $this->assertSame('SLTP/SEDERAJAT', $child->education);
        $this->assertSame('PELAJAR/MAHASISWA', $child->occupation);
        $this->assertSame('BELUM_KAWIN', $child->maritalStatus);
        $this->assertSame('ANAK', $child->familyRelation);
    }

    public function test_missing_optional_fields_stay_null_without_errors(): void
    {
        $text = implode("\n", [
            'NOMOR KARTU KELUARGA : 3207122801160001',
            'ALAMAT : JL. MELATI NO. 5',
            'RT/RW : 001/004',
            '',
            self::TABLE_HEADER,
            '1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA',
            '2 SITI AMINAH 3207124501010002 PEREMPUAN 05-04-2018 ISLAM KAWIN ISTRI',
        ]);

        $result = $this->parse($text, 88.0);

        $this->assertSame([], $result->validationErrors);
        $this->assertCount(2, $result->members);

        $spouse = $result->members[1];
        $this->assertNull($spouse->birthPlace);
        $this->assertNull($spouse->education);
        $this->assertNull($spouse->occupation);
        $this->assertSame('2018-04-05', $spouse->birthDate);
        $this->assertSame('KAWIN', $spouse->maritalStatus);
        $this->assertSame('ISTRI', $spouse->familyRelation);
    }

    public function test_missing_required_fields_are_reported_as_validation_errors(): void
    {
        // No NOMOR KK line: the KK number is a required field.
        $noKk = implode("\n", [
            'ALAMAT : JL. MELATI NO. 5',
            '',
            self::TABLE_HEADER,
            '1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA',
        ]);

        $result = $this->parse($noKk, 90.0);

        $this->assertNull($result->kkNumber);
        $this->assertCount(1, $result->members);
        $this->assertContains('Nomor KK tidak ditemukan atau tidak terbaca.', $result->validationErrors);

        // Header present but zero member rows: no NIK extracted (low yield).
        $noMembers = implode("\n", [
            'NOMOR KARTU KELUARGA : 3207122801160001',
            '',
            self::TABLE_HEADER,
        ]);

        $result = $this->parse($noMembers, 90.0);

        $this->assertSame('3207122801160001', $result->kkNumber);
        $this->assertSame([], $result->members);
        $this->assertContains('OCR tidak menemukan NIK anggota keluarga.', $result->validationErrors);
    }

    public function test_malformed_ocr_is_handled_gracefully(): void
    {
        $text = implode("\n", [
            'NOMOR KARTU KELUARGA : 3207122801160001',
            'ALAMAT : JL. MELATI NO. 5',
            'RT/RW : 001/004',
            '',
            self::TABLE_HEADER,
            '1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA',
            // 15-digit NIK: unreadable row, skipped with a warning.
            '2 SITI AMINAH 320712450101000 PEREMPUAN TANETE 05-04-2018 ISLAM SLTA/SEDERAJAT IBU RUMAH TANGGA KAWIN ISTRI',
            // Impossible date: extracted, then flagged by validation.
            '3 ANDI PRASETYO 3207121503050003 LAKI-LAKI BOGOR 32-13-2005 ISLAM SMP PELAJAR/MAHASISWA BELUM KAWIN ANAK',
            // Junk line: silently ignored (no digits, no label).
            'garbage text without numbers',
        ]);

        $result = $this->parse($text, 85.0);

        $this->assertCount(2, $result->members);

        $this->assertNotEmpty(
            array_filter($result->warnings, fn (string $w): bool => str_contains($w, 'NIK tidak terbaca') && str_contains($w, '320712450101000'))
        );

        $child = $result->members[1];
        $this->assertNull($child->birthDate);
        $this->assertContains('Tanggal lahir tidak valid pada anggota ke-3: 32-13-2005', $result->validationErrors);
        $this->assertSame('SLTP/SEDERAJAT', $child->education);
        $this->assertSame('ANAK', $child->familyRelation);
    }

    public function test_duplicate_labels_keep_first_occurrence_and_warn(): void
    {
        $text = implode("\n", [
            'NOMOR KARTU KELUARGA : 3207122801160001',
            'NOMOR KARTU KELUARGA : 3207122801160999',
            'ALAMAT : JL. MELATI NO. 5',
            'ALAMAT : JL. ANGGREK NO. 9',
            'RT/RW : 001/004',
            'RT/RW : 002/005',
            '',
            self::TABLE_HEADER,
            '1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA',
            '2 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA',
        ]);

        $result = $this->parse($text, 90.0);

        // First occurrence wins everywhere.
        $this->assertSame('3207122801160001', $result->kkNumber);
        $this->assertSame('JL. MELATI NO. 5', $result->address);
        $this->assertSame('001', $result->rt);
        $this->assertSame('004', $result->rw);

        // Duplicate NIK row dropped: exactly one member.
        $this->assertCount(1, $result->members);
        $this->assertContains('NIK duplikat diabaikan: 3207122801160001', $result->warnings);
        $this->assertContains('Nomor KK ganda tidak konsisten: 3207122801160999 diabaikan.', $result->warnings);
        $this->assertContains('Label ALAMAT ditemukan lebih dari satu kali. Nilai pertama dipertahankan.', $result->warnings);
        $this->assertContains('Nilai RT berbeda ditemukan pada dokumen. Nilai pertama dipertahankan.', $result->warnings);
        $this->assertContains('Nilai RW berbeda ditemukan pada dokumen. Nilai pertama dipertahankan.', $result->warnings);
    }

    public function test_low_confidence_text_flags_result_and_members(): void
    {
        $result = $this->parse(self::KK_TEXT, 45.0);

        $this->assertTrue($result->lowConfidence);
        $this->assertSame(45.0, $result->confidence);
        $this->assertNotEmpty($result->members);

        foreach ($result->members as $member) {
            $this->assertTrue($member->lowConfidence);
            $this->assertSame(45.0, $member->confidence);
        }
    }

    public function test_confidence_threshold_boundary_is_not_low_confidence(): void
    {
        $result = $this->parse(self::KK_TEXT, 70.0);

        $this->assertFalse($result->lowConfidence);
    }

    public function test_very_low_confidence_adds_unreadable_warning(): void
    {
        $result = $this->parse(self::KK_TEXT, 25.0);

        $this->assertTrue($result->lowConfidence);
        $this->assertContains('Gambar tidak terbaca dengan baik (confidence sangat rendah).', $result->warnings);
    }

    public function test_empty_ocr_result_yields_empty_parsed_result(): void
    {
        $result = $this->parse('', 0.0);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->memberCount());
        $this->assertNull($result->kkNumber);
        $this->assertNull($result->address);
        $this->assertSame([], $result->members);
        $this->assertTrue($result->lowConfidence);
        $this->assertContains('OCR tidak menghasilkan teks (gambar kosong atau tidak terbaca).', $result->warnings);
        $this->assertContains('OCR tidak menemukan NIK.', $result->validationErrors);

        // Whitespace-only input behaves identically.
        $blank = $this->parse("   \n \t \n", 0.0);
        $this->assertTrue($blank->isEmpty());
        $this->assertContains('OCR tidak menghasilkan teks (gambar kosong atau tidak terbaca).', $blank->warnings);
    }

    public function test_rt_rw_and_lingkungan_variants_are_extracted(): void
    {
        $text = implode("\n", [
            'NOMOR KK : 3207122801160001',
            'ALAMAT : JL. MELATI NO. 5',
            'RT : 003',
            'RW : 006',
            'LINGKUNGAN : I',
            '',
            self::TABLE_HEADER,
            '1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA',
        ]);

        $result = $this->parse($text, 90.0);

        $this->assertSame('3207122801160001', $result->kkNumber);
        $this->assertSame('003', $result->rt);
        $this->assertSame('006', $result->rw);
        $this->assertSame('I', $result->lingkungan);
        $this->assertSame([], $result->validationErrors);
    }

    public function test_wrapped_kk_number_and_spaced_nik_are_recovered(): void
    {
        $text = implode("\n", [
            'NOMOR KARTU KELUARGA :',
            '3207122801160001',
            'ALAMAT : JL. MELATI NO. 5',
            'RT/RW : 001/004',
            '',
            self::TABLE_HEADER,
            // NIK split across four tokens by Tesseract.
            '1 BUDI SANTOSO 3207 1228 0116 0001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA',
        ]);

        $result = $this->parse($text, 90.0);

        $this->assertSame('3207122801160001', $result->kkNumber);
        $this->assertCount(1, $result->members);
        $this->assertSame('3207122801160001', $result->members[0]->nik);
        $this->assertSame('BUDI SANTOSO', $result->members[0]->nama);
        $this->assertSame([], $result->validationErrors);
    }

    /**
     * Regression: OCR run-on di mana satu karakter menyatu ke label sebelum
     * titik dua (contoh: Lingkunganl:, LINGKUNGANl:, lingkunganx:).
     *
     * Satu karakter alfanumerik yang menyatu tetap harus dikenali sebagai
     * label Lingkungan, bukan diabaikan.
     */
    public function test_lingkungan_ocr_run_on_variants_are_extracted(): void
    {
        $variants = [
            'Lingkunganl: I',
            'LINGKUNGANl: I',
            'lingkunganx: I',
            'Lingkunganx : I',
            'LINGKUNGANl : II',
        ];

        foreach ($variants as $headerLine) {
            $text = implode("\n", [
                'NOMOR KK : 3207122801160001',
                'ALAMAT : JL. MELATI NO. 5',
                'RT/RW : 001/004',
                $headerLine,
                '',
                self::TABLE_HEADER,
                '1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA',
            ]);

            $result = $this->parse($text, 90.0);

            $this->assertNotSame(
                null,
                $result->lingkungan,
                'OCR run-on "'.$headerLine.'" seharusnya tetap mengenali field lingkungan.'
            );
            $this->assertSame(
                [],
                $result->validationErrors,
                'OCR run-on "'.$headerLine.'" seharusnya tidak menimbulkan validasi error.'
            );
        }
    }

    /**
     * Regression: kapitalisasi dan spacing yang sudah didukung sebelumnya
     * (Lingkungan:, LINGKUNGAN:, lingkungan :, Lingkungan  :) tetap bekerja
     * setelah perubahan regex.
     */
    public function test_existing_lingkungan_variants_still_work(): void
    {
        $variants = [
            ['Lingkungan: I', 'I'],
            ['LINGKUNGAN: II', 'II'],
            ['lingkungan : III', 'III'],
            ['Lingkungan  : IV', 'IV'],
            ['LINGKUNGAN : V', 'V'],
        ];

        foreach ($variants as [$headerLine, $expected]) {
            $text = implode("\n", [
                'NOMOR KK : 3207122801160001',
                'ALAMAT : JL. MELATI NO. 5',
                'RT/RW : 001/004',
                $headerLine,
                '',
                self::TABLE_HEADER,
                '1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA',
            ]);

            $result = $this->parse($text, 90.0);

            $this->assertSame(
                $expected,
                $result->lingkungan,
                'Variasi "'.$headerLine.'" seharusnya mengenali lingkungan="'.$expected.'".'
            );
            $this->assertSame([], $result->validationErrors);
        }
    }

    public function test_rt_rw_canonicalization_three_digits(): void
    {
        $cases = [
            ['RT.001 RW.004', '001', '004'],
            ['RT 01 / RW 04', '001', '004'],
            ['1/4', '001', '004'],
            ['RT/RW : 010/020', '010', '020'],
        ];

        foreach ($cases as [$rtRwLine, $expectedRt, $expectedRw]) {
            $text = implode("\n", [
                'NOMOR KK : 3207122801160001',
                'ALAMAT : JL. MELATI NO. 5',
                $rtRwLine,
                '',
                self::TABLE_HEADER,
                '1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-2016 ISLAM SLTA/SEDERAJAT BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA',
            ]);

            $result = $this->parse($text, 90.0);

            $this->assertSame($expectedRt, $result->rt, "Testing RT for {$rtRwLine}");
            $this->assertSame($expectedRw, $result->rw, "Testing RW for {$rtRwLine}");
        }
    }

    public function test_ktp_document_parsing_strategy(): void
    {
        $ktpText = implode("\n", [
            'PROVINSI SULAWESI SELATAN',
            'KABUPATEN BULUKUMBA',
            'NIK : 7302010101900001',
            'NAMA : AHMAD DAHLAN',
            'TEMPAT/TGL LAHIR : BULUKUMBA, 01-01-1990',
            'JENIS KELAMIN : LAKI-LAKI  GOL. DARAH : O',
            'ALAMAT : DUSUN TANETE',
            'RT/RW : 001/002',
            'KEL/DESA : TANETE',
            'KECAMATAN : BULUKUMPA',
            'AGAMA : ISLAM',
            'STATUS PERKAWINAN : KAWIN',
            'PEKERJAAN : PETANI/PEKEBUN',
            'KEWARGANEGARAAN : WNI',
            'BERLAKU HINGGA : SEUMUR HIDUP',
        ]);

        $result = $this->parse($ktpText, 88.0);

        $this->assertInstanceOf(ParsedOcrResult::class, $result);
        $this->assertFalse($result->isEmpty());
        $this->assertSame('001', $result->rt);
        $this->assertSame('002', $result->rw);
        $this->assertSame('DUSUN TANETE', $result->address);
        $this->assertSame('TANETE', $result->lingkungan);
        $this->assertCount(1, $result->members);

        $resident = $result->members[0];
        $this->assertSame('7302010101900001', $resident->nik);
        $this->assertSame('AHMAD DAHLAN', $resident->nama);
        $this->assertSame('BULUKUMBA', $resident->birthPlace);
        $this->assertSame('1990-01-01', $resident->birthDate);
        $this->assertSame('LAKI_LAKI', $resident->gender);
        $this->assertSame('ISLAM', $resident->religion);
        $this->assertSame('KAWIN', $resident->maritalStatus);
        $this->assertSame('PETANI', $resident->occupation);
        $this->assertSame('KEPALA_KELUARGA', $resident->familyRelation);
    }

    public function test_two_table_kk_row_stitching(): void
    {
        $twoTableKk = implode("\n", [
            'NOMOR KARTU KELUARGA : 7302010101900001',
            'ALAMAT : TANETE',
            'RT/RW : 001/004',
            'NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN',
            '1 BUDI SANTOSO 7302010101900001 LAKI-LAKI TANETE 28-01-1980 ISLAM SLTA/SEDERAJAT PETANI/PEKEBUN',
            '2 SITI AMINAH 7302014501850002 PEREMPUAN TANETE 05-04-1985 ISLAM SLTA/SEDERAJAT IBU RUMAH TANGGA',
            '3 ANDI PRATAMA 7302011010100003 LAKI-LAKI TANETE 10-10-2010 ISLAM BELUM/TIDAK BEKERJA',
            'NO STATUS PERKAWINAN STATUS HUBUNGAN DALAM KELUARGA KEWARGANEGARAAN NAMA AYAH NAMA IBU',
            '1 KAWIN KEPALA KELUARGA WNI HASAN FATIMAH',
            '2 KAWIN ISTRI WNI AHMAD AISYAH',
            '3 BELUM KAWIN ANAK WNI BUDI SANTOSO SITI AMINAH',
        ]);

        $result = $this->parse($twoTableKk, 92.0);

        $this->assertCount(3, $result->members);

        $this->assertSame('BUDI SANTOSO', $result->members[0]->nama);
        $this->assertSame('KAWIN', $result->members[0]->maritalStatus);
        $this->assertSame('KEPALA_KELUARGA', $result->members[0]->familyRelation);

        $this->assertSame('SITI AMINAH', $result->members[1]->nama);
        $this->assertSame('KAWIN', $result->members[1]->maritalStatus);
        $this->assertSame('ISTRI', $result->members[1]->familyRelation);

        $this->assertSame('ANDI PRATAMA', $result->members[2]->nama);
        $this->assertSame('BELUM_KAWIN', $result->members[2]->maritalStatus);
        $this->assertSame('ANAK', $result->members[2]->familyRelation);
    }

    public function test_education_canonical_mapping_and_aliases(): void
    {
        $cases = [
            'SMA/SEDERAJAT' => 'SLTA/SEDERAJAT',
            'SLTA/SEDERAJAT' => 'SLTA/SEDERAJAT',
            'SMA' => 'SLTA/SEDERAJAT',
            'SMK' => 'SLTA/SEDERAJAT',
            'SMP' => 'SLTP/SEDERAJAT',
            'SMP/SEDERAJAT' => 'SLTP/SEDERAJAT',
            'SD' => 'TAMAT SD/SEDERAJAT',
            'SD/SEDERAJAT' => 'TAMAT SD/SEDERAJAT',
            'TAMAT SD' => 'TAMAT SD/SEDERAJAT',
            'BELUM TAMAT SD' => 'BELUM TAMAT SD/SEDERAJAT',
            'TIDAK/BELUM SEKOLAH' => 'TIDAK/BELUM SEKOLAH',
            'D1' => 'DIPLOMA I/II',
            'D2' => 'DIPLOMA I/II',
            'D3' => 'AKADEMI/DIPLOMA III/SARJANA MUDA',
            'S1' => 'DIPLOMA IV/STRATA I',
            'S2' => 'STRATA II',
            'S3' => 'STRATA III',
        ];

        foreach ($cases as $ocrInput => $expectedCanonical) {
            $text = implode("\n", [
                'NOMOR KARTU KELUARGA : 3207120101234567',
                'ALAMAT : JL. MERDEKA NO. 10',
                'RT/RW : 001/004',
                '',
                self::TABLE_HEADER,
                "1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-1980 ISLAM {$ocrInput} BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA",
            ]);

            $result = $this->parse($text, 90.0);
            $this->assertCount(1, $result->members, "Failed to parse member for input '{$ocrInput}'");
            $this->assertSame($expectedCanonical, $result->members[0]->education, "Failed canonical mapping for input '{$ocrInput}'");
        }

        // Ambiguous / Garbage education input degrades gracefully to null
        $garbageText = implode("\n", [
            'NOMOR KARTU KELUARGA : 3207120101234567',
            'ALAMAT : JL. MERDEKA NO. 10',
            'RT/RW : 001/004',
            '',
            self::TABLE_HEADER,
            '1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-1980 ISLAM XYZ999GARBAGE BURUH HARIAN LEPAS KAWIN KEPALA KELUARGA',
        ]);
        $garbageResult = $this->parse($garbageText, 90.0);
        $this->assertCount(1, $garbageResult->members);
        $this->assertNull($garbageResult->members[0]->education);
    }

    public function test_marital_status_canonical_mapping_and_aliases(): void
    {
        $cases = [
            'BELUM KAWIN' => 'BELUM_KAWIN',
            'BELUMKAWIN' => 'BELUM_KAWIN',
            'BELUM KAWN' => 'BELUM_KAWIN',
            'BELUMKAWN' => 'BELUM_KAWIN',
            'KAWIN' => 'KAWIN',
            'KAW1N' => 'KAWIN',
            'KAWIN TERCATAT' => 'KAWIN',
            'CERAI HIDUP' => 'CERAI_HIDUP',
            'CERAIHIDUP' => 'CERAI_HIDUP',
            'CERAI MATI' => 'CERAI_MATI',
            'CERAIMATI' => 'CERAI_MATI',
        ];

        foreach ($cases as $ocrInput => $expectedCanonical) {
            $twoTable = implode("\n", [
                'NOMOR KARTU KELUARGA : 3207120101234567',
                'ALAMAT : JL. MERDEKA NO. 10',
                'RT/RW : 001/004',
                'NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN',
                '1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-1980 ISLAM SLTA/SEDERAJAT PETANI',
                'NO STATUS PERKAWINAN STATUS HUBUNGAN DALAM KELUARGA KEWARGANEGARAAN NAMA AYAH NAMA IBU',
                "1 {$ocrInput} KEPALA KELUARGA WNI HASAN FATIMAH",
            ]);

            $result = $this->parse($twoTable, 90.0);
            $this->assertCount(1, $result->members, "Failed to parse member for marital status input '{$ocrInput}'");
            $this->assertSame($expectedCanonical, $result->members[0]->maritalStatus, "Failed canonical marital status for input '{$ocrInput}'");
        }
    }

    public function test_family_relation_canonical_mapping_and_aliases(): void
    {
        $cases = [
            'KEPALA KELUARGA' => 'KEPALA_KELUARGA',
            'KEPALA KEL.' => 'KEPALA_KELUARGA',
            'ISTRI' => 'ISTRI',
            'ISTERI' => 'ISTRI',
            'ANAK' => 'ANAK',
            'ANAK KANDUNG' => 'ANAK',
            'MENANTU' => 'MENANTU',
            'CUCU' => 'CUCU',
            'ORANG TUA' => 'ORANG_TUA',
            'ORANGTUA' => 'ORANG_TUA',
            'MERTUA' => 'MERTUA',
            'FAMILI LAIN' => 'FAMILI_LAIN',
        ];

        foreach ($cases as $ocrInput => $expectedCanonical) {
            $twoTable = implode("\n", [
                'NOMOR KARTU KELUARGA : 3207120101234567',
                'ALAMAT : JL. MERDEKA NO. 10',
                'RT/RW : 001/004',
                'NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN',
                '1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-1980 ISLAM SLTA/SEDERAJAT PETANI',
                'NO STATUS PERKAWINAN STATUS HUBUNGAN DALAM KELUARGA KEWARGANEGARAAN NAMA AYAH NAMA IBU',
                "1 KAWIN {$ocrInput} WNI HASAN FATIMAH",
            ]);

            $result = $this->parse($twoTable, 90.0);
            $this->assertCount(1, $result->members, "Failed to parse member for relation input '{$ocrInput}'");
            $this->assertSame($expectedCanonical, $result->members[0]->familyRelation, "Failed canonical family relation for input '{$ocrInput}'");
        }
    }

    public function test_occupation_canonical_mapping_and_aliases(): void
    {
        $cases = [
            'WIRASWASTA' => 'WIRASWASTA',
            'MENGURUS RUMAH TANGGA' => 'IBU RUMAH TANGGA',
            'IBU RUMAH TANGGA' => 'IBU RUMAH TANGGA',
            'PELAJAR/MAHASISWA' => 'PELAJAR/MAHASISWA',
            'PELAJARIMAHASISWA' => 'PELAJAR/MAHASISWA',
            'PELAJAR' => 'PELAJAR/MAHASISWA',
            'MAHASISWA' => 'PELAJAR/MAHASISWA',
            'PETANI/PEKEBUN' => 'PETANI',
            'PETANI' => 'PETANI',
            'BURUH HARIAN LEPAS' => 'BURUH',
            'BURUH' => 'BURUH',
            'KARYAWAN SWASTA' => 'KARYAWAN SWASTA',
            'PEGAWAI NEGERI SIPIL' => 'PEGAWAI NEGERI SIPIL',
            'PNS' => 'PEGAWAI NEGERI SIPIL',
            'PEDAGANG' => 'PEDAGANG',
            'NELAYAN' => 'NELAYAN',
            'PENSIUNAN' => 'PENSIUNAN',
            'TUKANG' => 'TUKANG',
            'BELUM/TIDAK BEKERJA' => 'LAINNYA',
        ];

        foreach ($cases as $ocrInput => $expectedCanonical) {
            $text = implode("\n", [
                'NOMOR KARTU KELUARGA : 3207120101234567',
                'ALAMAT : JL. MERDEKA NO. 10',
                'RT/RW : 001/004',
                '',
                self::TABLE_HEADER,
                "1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-1980 ISLAM SLTA/SEDERAJAT {$ocrInput} KAWIN KEPALA KELUARGA",
            ]);

            $result = $this->parse($text, 90.0);
            $this->assertCount(1, $result->members, "Failed to parse member for occupation input '{$ocrInput}'");
            $this->assertSame($expectedCanonical, $result->members[0]->occupation, "Failed canonical occupation for input '{$ocrInput}'");
        }
    }

    public function test_family_relation_header_independence_and_variations(): void
    {
        $cases = [
            'KEPALAKELUARGA' => 'KEPALA_KELUARGA',
            'KEPALAKEUARGA' => 'KEPALA_KELUARGA',
            'KEPALA KEL.' => 'KEPALA_KELUARGA',
            'KEPALA KEL' => 'KEPALA_KELUARGA',
            'KEPALA' => 'KEPALA_KELUARGA',
            '1STRI' => 'ISTRI',
            'ISTERI' => 'ISTRI',
            'ISTRI' => 'ISTRI',
            'ANAK2' => 'ANAK',
            'ANAK-' => 'ANAK',
            'AN4K' => 'ANAK',
            'ANAK KANDUNG' => 'ANAK',
            'ORANG TUA' => 'ORANG_TUA',
            'ORANGTUA' => 'ORANG_TUA',
            'FAMILI LAIN' => 'FAMILI_LAIN',
            'FAMILI LAINNYA' => 'FAMILI_LAIN',
            'FAMILI' => 'FAMILI_LAIN',
            'PEMBANTU' => 'LAINNYA',
            'LAINNYA' => 'LAINNYA',
        ];

        foreach ($cases as $ocrInput => $expectedCanonical) {
            $twoTable = implode("\n", [
                'NOMOR KARTU KELUARGA : 3207120101234567',
                'ALAMAT : JL. MERDEKA NO. 10',
                'RT/RW : 001/004',
                'NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN',
                '1 BUDI SANTOSO 3207122801160001 LAKI-LAKI TANETE 28-01-1980 ISLAM SLTA/SEDERAJAT PETANI',
                'STATUS PERKAWINAN STATUS HUBUNGAN DALAM KELUARGA',
                "1 KAWIN {$ocrInput} WNI HASAN FATIMAH",
            ]);

            $result = $this->parse($twoTable, 90.0);
            $this->assertCount(1, $result->members, "Failed for relation variation: {$ocrInput}");
            $this->assertSame($expectedCanonical, $result->members[0]->familyRelation, "Canonical relation mismatch for: {$ocrInput}");
        }
    }

    public function test_two_table_kk_four_members_stitching(): void
    {
        $text = implode("\n", [
            'NOMOR KARTU KELUARGA : 7372010101230001',
            'ALAMAT : JL. POROS TANETE NO. 12',
            'RT/RW : 001/004',
            'KODE POS : 91111',
            'NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN',
            '1 ANDI SURYAMAN 7372010101800001 LAKI-LAKI PAREPARE 10-01-1980 ISLAM SLTA/SEDERAJAT WIRASWASTA',
            '2 SITI NURHALIZA 7372014502850002 PEREMPUAN PAREPARE 15-02-1985 ISLAM SLTA/SEDERAJAT IBU RUMAH TANGGA',
            '3 MUHAMMAD FIKRI 7372011003100003 LAKI-LAKI PAREPARE 20-03-2010 ISLAM SLTP/SEDERAJAT PELAJAR/MAHASISWA',
            '4 NUR AULIA 7372015004150004 PEREMPUAN PAREPARE 25-04-2015 ISLAM TAMAT SD/SEDERAJAT PELAJAR/MAHASISWA',
            'NO STATUS PERKAWINAN STATUS HUBUNGAN DALAM KELUARGA KEWARGANEGARAAN NAMA AYAH NAMA IBU',
            '1 KAWIN KEPALA KELUARGA WNI HASAN SITI',
            '2 KAWIN ISTERI WNI AHMAD FATIMAH',
            '3 BELUM KAWIN ANAK WNI ANDI SURYAMAN SITI NURHALIZA',
            '4 BELUM KAWIN ANAK2 WNI ANDI SURYAMAN SITI NURHALIZA',
        ]);

        $result = $this->parse($text, 95.0);

        $this->assertCount(4, $result->members);
        $this->assertSame('91111', $result->postalCode);

        // Row 1
        $this->assertSame('ANDI SURYAMAN', $result->members[0]->nama);
        $this->assertSame('KEPALA_KELUARGA', $result->members[0]->familyRelation);
        $this->assertSame('KAWIN', $result->members[0]->maritalStatus);

        // Row 2
        $this->assertSame('SITI NURHALIZA', $result->members[1]->nama);
        $this->assertSame('ISTRI', $result->members[1]->familyRelation);
        $this->assertSame('KAWIN', $result->members[1]->maritalStatus);

        // Row 3
        $this->assertSame('MUHAMMAD FIKRI', $result->members[2]->nama);
        $this->assertSame('ANAK', $result->members[2]->familyRelation);
        $this->assertSame('BELUM_KAWIN', $result->members[2]->maritalStatus);

        // Row 4
        $this->assertSame('NUR AULIA', $result->members[3]->nama);
        $this->assertSame('ANAK', $result->members[3]->familyRelation);
        $this->assertSame('BELUM_KAWIN', $result->members[3]->maritalStatus);
    }

    public function test_family_relation_broken_or_split_header(): void
    {
        // Split header across two lines
        $text = implode("\n", [
            'NOMOR KARTU KELUARGA : 7372010101230001',
            'ALAMAT : JL. POROS TANETE NO. 12',
            'RT/RW : 001/004',
            'NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN',
            '1 ANDI SURYAMAN 7372010101800001 LAKI-LAKI PAREPARE 10-01-1980 ISLAM SLTA/SEDERAJAT WIRASWASTA',
            '2 SITI NURHALIZA 7372014502850002 PEREMPUAN PAREPARE 15-02-1985 ISLAM SLTA/SEDERAJAT IBU RUMAH TANGGA',
            'STATUS PERKAWINAN STATUS HUBUNGAN DALAM',
            'KELUARGA',
            '(1) (2) (3)',
            '1 KAWIN KEPALA KEL. WNI HASAN SITI',
            '2 KAWIN ISTRI WNI AHMAD FATIMAH',
        ]);

        $result = $this->parse($text, 92.0);

        $this->assertCount(2, $result->members);
        $this->assertSame('KEPALA_KELUARGA', $result->members[0]->familyRelation);
        $this->assertSame('ISTRI', $result->members[1]->familyRelation);
    }

    public function test_family_relation_missing_header_fallback(): void
    {
        // Table 2 without header at all
        $text = implode("\n", [
            'NOMOR KARTU KELUARGA : 7372010101230001',
            'ALAMAT : JL. POROS TANETE NO. 12',
            'RT/RW : 001/004',
            'NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN',
            '1 ANDI SURYAMAN 7372010101800001 LAKI-LAKI PAREPARE 10-01-1980 ISLAM SLTA/SEDERAJAT WIRASWASTA',
            '2 SITI NURHALIZA 7372014502850002 PEREMPUAN PAREPARE 15-02-1985 ISLAM SLTA/SEDERAJAT IBU RUMAH TANGGA',
            '1 KAWIN KEPALAKELUARGA WNI HASAN SITI',
            '2 KAWIN ISTERI WNI AHMAD FATIMAH',
        ]);

        $result = $this->parse($text, 92.0);

        $this->assertCount(2, $result->members);
        $this->assertSame('KEPALA_KELUARGA', $result->members[0]->familyRelation);
        $this->assertSame('ISTRI', $result->members[1]->familyRelation);
    }

    public function test_family_relation_garbage_degradation(): void
    {
        $text = implode("\n", [
            'NOMOR KARTU KELUARGA : 7372010101230001',
            'ALAMAT : JL. POROS TANETE NO. 12',
            'RT/RW : 001/004',
            'NO NAMA NIK JENIS KELAMIN TEMPAT LAHIR TANGGAL LAHIR AGAMA PENDIDIKAN PEKERJAAN',
            '1 ANDI SURYAMAN 7372010101800001 LAKI-LAKI PAREPARE 10-01-1980 ISLAM SLTA/SEDERAJAT WIRASWASTA',
            'STATUS PERKAWINAN STATUS HUBUNGAN DALAM KELUARGA',
            '1 KAWIN XYZGARBAGE999 WNI HASAN SITI',
        ]);

        $result = $this->parse($text, 92.0);

        $this->assertCount(1, $result->members);
        $this->assertNull($result->members[0]->familyRelation);
    }

    public function test_multiline_address_does_not_leak_members_into_address(): void
    {
        $text = implode("\n", [
            'NOMOR KARTU KELUARGA : 7372010101230001',
            'ALAMAT : JL. POROS PARE-PARE NO. 12',
            'KOMPLEK PERUMAHAN INDAH BLOK B',
            'RT/RW : 001/004',
            'KELURAHAN : TANETE',
            'KECAMATAN : TANETE',
            '1 ANDI SURYAMAN 7372010101800001 LAKI-LAKI PAREPARE 10-01-1980 ISLAM SLTA/SEDERAJAT WIRASWASTA KAWIN KEPALA KELUARGA',
            '2 SITI NURHALIZA 7372014502850002 PEREMPUAN PAREPARE 15-02-1985 ISLAM SLTA/SEDERAJAT IBU RUMAH TANGGA KAWIN ISTRI',
        ]);

        $result = $this->parse($text, 92.0);

        $this->assertSame('7372010101230001', $result->kkNumber);
        $this->assertSame('JL. POROS PARE-PARE NO. 12 KOMPLEK PERUMAHAN INDAH BLOK B', $result->address);
        $this->assertSame('001', $result->rt);
        $this->assertSame('004', $result->rw);
        $this->assertCount(2, $result->members);
        $this->assertSame('ANDI SURYAMAN', $result->members[0]->nama);
        $this->assertSame('7372010101800001', $result->members[0]->nik);
        $this->assertSame('SITI NURHALIZA', $result->members[1]->nama);
        $this->assertSame('7372014502850002', $result->members[1]->nik);
        $this->assertStringNotContainsString('ANDI SURYAMAN', (string) $result->address);
        $this->assertStringNotContainsString('7372010101800001', (string) $result->address);
    }

    public function test_degraded_table_header_parses_all_members_without_loss(): void
    {
        // Table header text corrupted by OCR noise or missing entirely
        $text = implode("\n", [
            'NOMOR KARTU KELUARGA : 7372010101230001',
            'ALAMAT : JL. JENDERAL SUDIRMAN NO. 99',
            'RT/RW : 002/005',
            '1 MUHAMMAD DAHLAN 7372010105700001 LAKI-LAKI BARRU 01-05-1970 ISLAM S1 WIRASWASTA KAWIN KEPALA KELUARGA',
            '2 MARIAM 7372014107750002 PEREMPUAN BARRU 01-07-1975 ISLAM SMA IBU RUMAH TANGGA KAWIN ISTRI',
            '3 FAHRI DAHLAN 7372011010020003 LAKI-LAKI BARRU 10-10-2002 ISLAM SMA MAHASISWA BELUM KAWIN ANAK',
        ]);

        $result = $this->parse($text, 90.0);

        $this->assertSame('7372010101230001', $result->kkNumber);
        $this->assertSame('JL. JENDERAL SUDIRMAN NO. 99', $result->address);
        $this->assertSame('002', $result->rt);
        $this->assertSame('005', $result->rw);
        $this->assertCount(3, $result->members);
        $this->assertSame('MUHAMMAD DAHLAN', $result->members[0]->nama);
        $this->assertSame('7372010105700001', $result->members[0]->nik);
        $this->assertSame('MARIAM', $result->members[1]->nama);
        $this->assertSame('7372014107750002', $result->members[1]->nik);
        $this->assertSame('FAHRI DAHLAN', $result->members[2]->nama);
        $this->assertSame('7372011010020003', $result->members[2]->nik);
    }

    private function parse(string $rawText, float $confidence): ParsedOcrResult
    {
        return (new OcrParsingService)->parse($rawText, $confidence);
    }
}
