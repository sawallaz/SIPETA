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
        $this->assertSame('BURUH HARIAN LEPAS', $head->occupation);
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
        $this->assertSame('SMP', $child->education);
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
        $this->assertContains('Nomor KK tidak ditemukan', $result->validationErrors);

        // Header present but zero member rows: no NIK extracted (low yield).
        $noMembers = implode("\n", [
            'NOMOR KARTU KELUARGA : 3207122801160001',
            '',
            self::TABLE_HEADER,
        ]);

        $result = $this->parse($noMembers, 90.0);

        $this->assertSame('3207122801160001', $result->kkNumber);
        $this->assertSame([], $result->members);
        $this->assertContains('OCR tidak menemukan NIK', $result->validationErrors);
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
        $this->assertSame('SMP', $child->education);
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
        $this->assertContains('Nomor KK ganda tidak konsisten: 3207122801160999 (diabaikan)', $result->warnings);
        $this->assertContains('Label duplikat diabaikan: ALAMAT', $result->warnings);
        $this->assertContains('Label duplikat diabaikan: RT/RW', $result->warnings);
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
        $this->assertContains('Gambar tidak terbaca (confidence sangat rendah)', $result->warnings);
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
        $this->assertContains('OCR tidak menghasilkan teks (gambar kosong atau tidak terbaca)', $result->warnings);
        $this->assertContains('OCR tidak menemukan NIK', $result->validationErrors);

        // Whitespace-only input behaves identically.
        $blank = $this->parse("   \n \t \n", 0.0);
        $this->assertTrue($blank->isEmpty());
        $this->assertContains('OCR tidak menghasilkan teks (gambar kosong atau tidak terbaca)', $blank->warnings);
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

    private function parse(string $rawText, float $confidence): ParsedOcrResult
    {
        return (new OcrParsingService)->parse($rawText, $confidence);
    }
}
