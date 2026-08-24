<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * OCR text parser untuk dokumen Kartu Keluarga.
 *
 * Tahap ini hanya melakukan:
 *
 * RAW OCR TEXT
 *      ↓
 * normalisasi
 *      ↓
 * parsing header KK
 *      ↓
 * parsing tabel anggota
 *      ↓
 * validasi hasil
 *      ↓
 * ParsedOcrResult
 *
 * Tidak ada database write di class ini.
 *
 * Prinsip:
 * - OCR adalah assistant, bukan sumber kebenaran final.
 * - Data yang tidak terbaca tetap null.
 * - Jangan menebak data penduduk.
 * - NIK harus 16 digit.
 * - Duplicate NIK di satu hasil OCR hanya diambil sekali.
 * - Semua masalah parsing masuk warnings / validationErrors.
 */
final class OcrParsingService
{
    /**
     * Header KK.
     *
     * Urutkan label yang lebih panjang terlebih dahulu agar:
     *
     * NOMOR KARTU KELUARGA
     * tidak terbaca sebagai
     * NOMOR KK
     */
    private const HEADER_KEYS = [
        'NOMOR_KARTU_KELUARGA' => 'NOMOR KARTU KELUARGA',
        'NOMOR_KK' => 'NOMOR KK',
        'NO_KK' => 'NO KK',
        'NO_KK_DOT' => 'NO. KK',
        'NO_DOT' => 'NO.',
        'NO' => 'NO',
        'RT_RW' => 'RT/RW',
        'LINGKUNGAN' => 'LINGKUNGAN',
        'ALAMAT' => 'ALAMAT',
        'KODE_POS' => 'KODE POS',
        'KODEPOS' => 'KODEPOS',
        'RT' => 'RT',
        'RW' => 'RW',
    ];

    /**
     * Agama.
     *
     * Key = nilai canonical yang akan dikirim ke layer berikutnya.
     */
    private const RELIGIONS = [
        'ISLAM' => ['ISLAM'],
        'KRISTEN' => ['KRISTEN'],
        'KATOLIK' => ['KATOLIK'],
        'HINDU' => ['HINDU'],
        'BUDDHA' => ['BUDDHA'],
        'KONGHUCU' => ['KONGHUCU'],
    ];

    public const CANONICAL_EDUCATIONS = [
        'TIDAK/BELUM SEKOLAH',
        'BELUM TAMAT SD/SEDERAJAT',
        'TAMAT SD/SEDERAJAT',
        'SLTP/SEDERAJAT',
        'SLTA/SEDERAJAT',
        'DIPLOMA I/II',
        'AKADEMI/DIPLOMA III/SARJANA MUDA',
        'DIPLOMA IV/STRATA I',
        'STRATA II',
        'STRATA III',
    ];

    /**
     * Pendidikan vocabulary mapping to canonical output.
     */
    private const EDUCATIONS = [
        // 1. TIDAK/BELUM SEKOLAH
        ['TIDAK/BELUM SEKOLAH', ['TIDAK/BELUM', 'SEKOLAH']],
        ['TIDAK/BELUM SEKOLAH', ['TIDAK', 'BELUM', 'SEKOLAH']],
        ['TIDAK/BELUM SEKOLAH', ['BELUM', 'SEKOLAH']],
        ['TIDAK/BELUM SEKOLAH', ['TIDAK', 'SEKOLAH']],
        ['TIDAK/BELUM SEKOLAH', ['TIDAK/BELUM']],

        // 2. BELUM TAMAT SD/SEDERAJAT
        ['BELUM TAMAT SD/SEDERAJAT', ['BELUM', 'TAMAT', 'SD/SEDERAJAT']],
        ['BELUM TAMAT SD/SEDERAJAT', ['BELUM', 'TAMAT', 'SD']],
        ['BELUM TAMAT SD/SEDERAJAT', ['BELUM', 'TAMAT']],

        // 3. TAMAT SD/SEDERAJAT
        ['TAMAT SD/SEDERAJAT', ['TAMAT', 'SD/SEDERAJAT']],
        ['TAMAT SD/SEDERAJAT', ['TAMAT', 'SD']],
        ['TAMAT SD/SEDERAJAT', ['SD/SEDERAJAT']],
        ['TAMAT SD/SEDERAJAT', ['SD']],

        // 4. SLTP/SEDERAJAT
        ['SLTP/SEDERAJAT', ['SLTP/SEDERAJAT']],
        ['SLTP/SEDERAJAT', ['SLTP']],
        ['SLTP/SEDERAJAT', ['SMP/SEDERAJAT']],
        ['SLTP/SEDERAJAT', ['SMP']],
        ['SLTP/SEDERAJAT', ['MTS/SEDERAJAT']],
        ['SLTP/SEDERAJAT', ['MTS']],

        // 5. SLTA/SEDERAJAT
        ['SLTA/SEDERAJAT', ['SLTA/SEDERAJAT']],
        ['SLTA/SEDERAJAT', ['SLTA']],
        ['SLTA/SEDERAJAT', ['SMA/SEDERAJAT']],
        ['SLTA/SEDERAJAT', ['SMA']],
        ['SLTA/SEDERAJAT', ['SMK/SEDERAJAT']],
        ['SLTA/SEDERAJAT', ['SMK']],
        ['SLTA/SEDERAJAT', ['MA/SEDERAJAT']],
        ['SLTA/SEDERAJAT', ['MA']],

        // 6. DIPLOMA I/II
        ['DIPLOMA I/II', ['DIPLOMA', 'I/II']],
        ['DIPLOMA I/II', ['DIPLOMA', 'I']],
        ['DIPLOMA I/II', ['DIPLOMA', 'II']],
        ['DIPLOMA I/II', ['DIPLOMA', '1/2']],
        ['DIPLOMA I/II', ['DIPLOMA', '1']],
        ['DIPLOMA I/II', ['DIPLOMA', '2']],
        ['DIPLOMA I/II', ['D-I']],
        ['DIPLOMA I/II', ['D-II']],
        ['DIPLOMA I/II', ['D1']],
        ['DIPLOMA I/II', ['D2']],
        ['DIPLOMA I/II', ['D', 'I']],
        ['DIPLOMA I/II', ['D', 'II']],
        ['DIPLOMA I/II', ['D', '1']],
        ['DIPLOMA I/II', ['D', '2']],

        // 7. AKADEMI/DIPLOMA III/SARJANA MUDA
        ['AKADEMI/DIPLOMA III/SARJANA MUDA', ['AKADEMI/DIPLOMA', 'III/SARJANA', 'MUDA']],
        ['AKADEMI/DIPLOMA III/SARJANA MUDA', ['AKADEMI/DIPLOMA', 'III']],
        ['AKADEMI/DIPLOMA III/SARJANA MUDA', ['DIPLOMA', 'III']],
        ['AKADEMI/DIPLOMA III/SARJANA MUDA', ['DIPLOMA', '3']],
        ['AKADEMI/DIPLOMA III/SARJANA MUDA', ['AKADEMI']],
        ['AKADEMI/DIPLOMA III/SARJANA MUDA', ['SARJANA', 'MUDA']],
        ['AKADEMI/DIPLOMA III/SARJANA MUDA', ['D-III']],
        ['AKADEMI/DIPLOMA III/SARJANA MUDA', ['D3']],
        ['AKADEMI/DIPLOMA III/SARJANA MUDA', ['D', 'III']],
        ['AKADEMI/DIPLOMA III/SARJANA MUDA', ['D', '3']],

        // 8. DIPLOMA IV/STRATA I
        ['DIPLOMA IV/STRATA I', ['DIPLOMA', 'IV/STRATA', 'I']],
        ['DIPLOMA IV/STRATA I', ['DIPLOMA', 'IV']],
        ['DIPLOMA IV/STRATA I', ['DIPLOMA', '4']],
        ['DIPLOMA IV/STRATA I', ['STRATA', 'I']],
        ['DIPLOMA IV/STRATA I', ['STRATA', '1']],
        ['DIPLOMA IV/STRATA I', ['SARJANA']],
        ['DIPLOMA IV/STRATA I', ['D-IV']],
        ['DIPLOMA IV/STRATA I', ['D4']],
        ['DIPLOMA IV/STRATA I', ['D', 'IV']],
        ['DIPLOMA IV/STRATA I', ['D', '4']],
        ['DIPLOMA IV/STRATA I', ['S-I']],
        ['DIPLOMA IV/STRATA I', ['S1']],
        ['DIPLOMA IV/STRATA I', ['S', 'I']],
        ['DIPLOMA IV/STRATA I', ['S', '1']],

        // 9. STRATA II
        ['STRATA II', ['STRATA', 'II']],
        ['STRATA II', ['STRATA', '2']],
        ['STRATA II', ['MAGISTER']],
        ['STRATA II', ['S-II']],
        ['STRATA II', ['S2']],
        ['STRATA II', ['S', 'II']],
        ['STRATA II', ['S', '2']],

        // 10. STRATA III
        ['STRATA III', ['STRATA', 'III']],
        ['STRATA III', ['STRATA', '3']],
        ['STRATA III', ['DOKTOR']],
        ['STRATA III', ['S-III']],
        ['STRATA III', ['S3']],
        ['STRATA III', ['S', 'III']],
        ['STRATA III', ['S', '3']],
    ];

    public const CANONICAL_OCCUPATIONS = [
        'PEGAWAI NEGERI SIPIL',
        'IBU RUMAH TANGGA',
        'BURUH',
        'KARYAWAN SWASTA',
        'PELAJAR/MAHASISWA',
        'PETANI',
        'PEDAGANG',
        'NELAYAN',
        'WIRASWASTA',
        'PENSIUNAN',
        'TUKANG',
        'LAINNYA',
    ];

    /**
     * Pekerjaan.
     */
    private const OCCUPATIONS = [
        // PEGAWAI NEGERI SIPIL
        ['PEGAWAI NEGERI SIPIL', ['PEGAWAI', 'NEGERI', 'SIPIL']],
        ['PEGAWAI NEGERI SIPIL', ['PEGAWAI', 'NEGERI']],
        ['PEGAWAI NEGERI SIPIL', ['PNS']],
        ['PEGAWAI NEGERI SIPIL', ['ASN']],

        // IBU RUMAH TANGGA
        ['IBU RUMAH TANGGA', ['MENGURUS', 'RUMAH', 'TANGGA']],
        ['IBU RUMAH TANGGA', ['IBU', 'RUMAH', 'TANGGA']],
        ['IBU RUMAH TANGGA', ['RUMAH', 'TANGGA']],

        // BURUH
        ['BURUH', ['BURUH', 'HARIAN', 'LEPAS']],
        ['BURUH', ['BURUH', 'HARIAN']],
        ['BURUH', ['BURUH', 'TANI']],
        ['BURUH', ['BURUH', 'PABRIK']],
        ['BURUH', ['BURUH']],

        // KARYAWAN SWASTA
        ['KARYAWAN SWASTA', ['KARYAWAN', 'SWASTA']],
        ['KARYAWAN SWASTA', ['KARYAWAN', 'BUMN']],
        ['KARYAWAN SWASTA', ['KARYAWAN', 'BUMD']],
        ['KARYAWAN SWASTA', ['PEGAWAI', 'SWASTA']],
        ['KARYAWAN SWASTA', ['KARYAWAN']],

        // PELAJAR/MAHASISWA
        ['PELAJAR/MAHASISWA', ['PELAJAR/MAHASISWA']],
        ['PELAJAR/MAHASISWA', ['PELAJARIMAHASISWA']],
        ['PELAJAR/MAHASISWA', ['PELAJAR', 'MAHASISWA']],
        ['PELAJAR/MAHASISWA', ['PELAJAR']],
        ['PELAJAR/MAHASISWA', ['MAHASISWA']],

        // PETANI
        ['PETANI', ['PETANI/PEKEBUN']],
        ['PETANI', ['PETANI', 'PEKEBUN']],
        ['PETANI', ['PETANI']],
        ['PETANI', ['PEKEBUN']],

        // PEDAGANG
        ['PEDAGANG', ['PEDAGANG']],
        ['PEDAGANG', ['PERDAGANGAN']],

        // NELAYAN
        ['NELAYAN', ['NELAYAN/PERIKANAN']],
        ['NELAYAN', ['NELAYAN']],
        ['NELAYAN', ['PERIKANAN']],

        // WIRASWASTA
        ['WIRASWASTA', ['WIRASWASTA']],
        ['WIRASWASTA', ['WIRAUSAHA']],

        // PENSIUNAN
        ['PENSIUNAN', ['PENSIUNAN']],
        ['PENSIUNAN', ['PENSIUN']],

        // TUKANG
        ['TUKANG', ['TUKANG', 'KAYU']],
        ['TUKANG', ['TUKANG', 'BATU']],
        ['TUKANG', ['TUKANG', 'JAHIT']],
        ['TUKANG', ['TUKANG', 'CUKUR']],
        ['TUKANG', ['TUKANG', 'LAS']],
        ['TUKANG', ['TUKANG']],

        // BELUM/TIDAK BEKERJA / LAINNYA
        ['LAINNYA', ['BELUM/TIDAK', 'BEKERJA']],
        ['LAINNYA', ['BELUM', 'TIDAK', 'BEKERJA']],
        ['LAINNYA', ['TIDAK', 'BEKERJA']],
        ['LAINNYA', ['BELUM', 'BEKERJA']],
        ['LAINNYA', ['LAINNYA']],
    ];

    public const CANONICAL_MARITAL_STATUSES = [
        'BELUM_KAWIN',
        'KAWIN',
        'CERAI_HIDUP',
        'CERAI_MATI',
    ];

    /**
     * Status perkawinan.
     */
    private const MARITAL_STATUSES = [
        // BELUM_KAWIN
        ['BELUM_KAWIN', ['BELUM', 'KAWIN']],
        ['BELUM_KAWIN', ['BELUMKAWIN']],
        ['BELUM_KAWIN', ['BELUM', 'KAWN']],
        ['BELUM_KAWIN', ['BELUMKAWN']],
        ['BELUM_KAWIN', ['BLM', 'KAWIN']],
        ['BELUM_KAWIN', ['BELUM', 'MENIKAH']],
        ['BELUM_KAWIN', ['BELUM', 'NIKAH']],

        // KAWIN
        ['KAWIN', ['KAWIN', 'TERCATAT']],
        ['KAWIN', ['KAWIN', 'BELUM', 'TERCATAT']],
        ['KAWIN', ['KAWIN']],
        ['KAWIN', ['MENIKAH']],
        ['KAWIN', ['NIKAH']],

        // CERAI_HIDUP
        ['CERAI_HIDUP', ['CERAI', 'HIDUP']],
        ['CERAI_HIDUP', ['CERAIHIDUP']],
        ['CERAI_HIDUP', ['CERAI']],
        ['CERAI_HIDUP', ['DUDA']],
        ['CERAI_HIDUP', ['JANDA']],

        // CERAI_MATI
        ['CERAI_MATI', ['CERAI', 'MATI']],
        ['CERAI_MATI', ['CERAIMATI']],
    ];

    public const CANONICAL_FAMILY_RELATIONS = [
        'KEPALA_KELUARGA',
        'ISTRI',
        'ANAK',
        'MENANTU',
        'CUCU',
        'ORANG_TUA',
        'MERTUA',
        'FAMILI_LAIN',
        'LAINNYA',
    ];

    /**
     * Hubungan keluarga.
     */
    private const FAMILY_RELATIONS = [
        // KEPALA_KELUARGA
        ['KEPALA_KELUARGA', ['KEPALA', 'KELUARGA']],
        ['KEPALA_KELUARGA', ['KEPALA', 'KEL.']],
        ['KEPALA_KELUARGA', ['KEPALA', 'KEL']],
        ['KEPALA_KELUARGA', ['KEPALAKELUARGA']],
        ['KEPALA_KELUARGA', ['KEPALAKEUARGA']],
        ['KEPALA_KELUARGA', ['KEPALAKEL']],
        ['KEPALA_KELUARGA', ['KEPALA']],

        // ISTRI
        ['ISTRI', ['ISTRI']],
        ['ISTRI', ['ISTERI']],
        ['ISTRI', ['1STRI']],
        ['ISTRI', ['ISTRI', 'KEPALA', 'KELUARGA']],

        // ANAK
        ['ANAK', ['ANAK', 'KANDUNG']],
        ['ANAK', ['ANAK', 'ANGKAT']],
        ['ANAK', ['ANAK', 'TIRI']],
        ['ANAK', ['ANAK']],
        ['ANAK', ['ANAK2']],
        ['ANAK', ['ANAK-']],
        ['ANAK', ['AN4K']],

        // MENANTU
        ['MENANTU', ['MENANTU']],

        // CUCU
        ['CUCU', ['CUCU']],

        // ORANG_TUA
        ['ORANG_TUA', ['ORANG', 'TUA']],
        ['ORANG_TUA', ['ORANGTUA']],

        // MERTUA
        ['MERTUA', ['MERTUA']],

        // FAMILI_LAIN
        ['FAMILI_LAIN', ['FAMILI', 'LAIN']],
        ['FAMILI_LAIN', ['FAMILI', 'LAINNYA']],
        ['FAMILI_LAIN', ['FAMILI']],
        ['FAMILI_LAIN', ['FAMILILAIN']],

        // LAINNYA
        ['LAINNYA', ['PEMBANTU']],
        ['LAINNYA', ['LAINNYA']],
        ['LAINNYA', ['LAIN']],
    ];

    /**
     * Opsi agama untuk UI review.
     *
     * @return array<string, string>
     */
    public static function religionOptions(): array
    {
        return array_combine(
            array_keys(self::RELIGIONS),
            array_keys(self::RELIGIONS),
        );
    }

    /**
     * Opsi pendidikan untuk UI review.
     *
     * @return array<string, string>
     */
    public static function educationOptions(): array
    {
        return array_combine(
            self::CANONICAL_EDUCATIONS,
            self::CANONICAL_EDUCATIONS,
        );
    }

    /**
     * Opsi pekerjaan untuk UI review.
     *
     * @return array<string, string>
     */
    public static function occupationOptions(): array
    {
        return array_combine(
            self::CANONICAL_OCCUPATIONS,
            self::CANONICAL_OCCUPATIONS,
        );
    }

    /**
     * Parse raw OCR text menjadi ParsedOcrResult.
     */
    public function parse(
        string $rawText,
        float $confidence = 0.0,
    ): ParsedOcrResult {
        $startedAt = microtime(true);

        $threshold = (float) config(
            'ocr.confidence_threshold',
            70,
        );

        $lowConfidence = $confidence < $threshold;

        $warnings = [];
        $validationErrors = [];

        if ($confidence < 30) {
            $warnings[] =
                'Gambar tidak terbaca dengan baik (confidence sangat rendah).';
        }

        $lines = $this->normalizeLines($rawText);

        if ($lines === []) {
            $warnings[] =
                'OCR tidak menghasilkan teks (gambar kosong atau tidak terbaca).';

            $validationErrors[] =
                'OCR tidak menemukan NIK.';

            $this->log(
                $confidence,
                $lowConfidence,
                0,
                count($warnings),
                $startedAt,
            );

            return new ParsedOcrResult(
                confidence: $confidence,
                lowConfidence: $lowConfidence,
                kkNumber: null,
                address: null,
                rt: null,
                rw: null,
                lingkungan: null,
                members: [],
                warnings: $warnings,
                validationErrors: $validationErrors,
                durationMs: $this->elapsedMs($startedAt),
            );
        }

        if ($this->isKtpDocument($lines)) {
            return $this->parseKtp(
                $lines,
                $confidence,
                $lowConfidence,
                $warnings,
                $validationErrors,
                $startedAt,
            );
        }

        [
            $kkNumber,
            $address,
            $rt,
            $rw,
            $lingkungan,
            $postalCode,
        ] = $this->parseHeader(
            $lines,
            $warnings,
        );

        $members = $this->parseMembers(
            $lines,
            $confidence,
            $lowConfidence,
            $warnings,
            $validationErrors,
        );

        /*
         * Minimum viable OCR result:
         *
         * - Nomor KK
         * - minimal satu NIK anggota
         */
        if ($kkNumber === null) {
            $validationErrors[] =
                'Nomor KK tidak ditemukan atau tidak terbaca.';
        }

        if ($members === []) {
            $validationErrors[] =
                'OCR tidak menemukan NIK anggota keluarga.';
        }

        /*
         * Validasi tambahan yang aman.
         *
         * Ini tidak menghapus hasil OCR.
         * Hanya memberi warning kepada operator.
         */
        $this->validateMemberConsistency(
            $members,
            $warnings,
            $validationErrors,
        );

        $this->log(
            $confidence,
            $lowConfidence,
            count($members),
            count($warnings),
            $startedAt,
        );

        return new ParsedOcrResult(
            confidence: $confidence,
            lowConfidence: $lowConfidence,
            kkNumber: $kkNumber,
            address: $address,
            rt: $rt,
            rw: $rw,
            lingkungan: $lingkungan,
            members: $members,
            warnings: $warnings,
            validationErrors: $validationErrors,
            durationMs: $this->elapsedMs($startedAt),
            postalCode: $postalCode,
        );
    }

    /**
     * Bersihkan raw OCR menjadi baris-baris bermakna.
     *
     * Jangan mengubah isi data terlalu agresif di sini.
     * Koreksi OCR hanya dilakukan pada token yang memang aman
     * untuk dinormalisasi.
     *
     * @return array<int, string>
     */
    private function normalizeLines(string $rawText): array
    {
        $rawText = str_replace(
            [
                "\r\n",
                "\r",
                "\u{00A0}",
                "\u{200B}",
            ],
            [
                "\n",
                "\n",
                ' ',
                '',
            ],
            $rawText,
        );

        $lines = [];

        foreach (
            preg_split('/\n/u', $rawText) ?: [] as $line
        ) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            /*
             * Collapse repeated whitespace.
             */
            $line = preg_replace(
                '/[ \t]+/u',
                ' ',
                $line,
            ) ?? $line;

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Parse bagian header KK.
     *
     * Field:
     * - nomor KK
     * - alamat
     * - RT
     * - RW
     * - lingkungan
     *
     * Header hanya diproses sebelum tabel anggota.
     *
     * Ini penting agar baris anggota tidak dianggap sebagai
     * kelanjutan alamat.
     *
     * @param  array<int, string>  $lines
     * @param  array<int, string>  $warnings
     * @return array{
     *     0: string|null,
     *     1: string|null,
     *     2: string|null,
     *     3: string|null,
     *     4: string|null
     * }
     */
    private function parseHeader(
        array $lines,
        array &$warnings,
    ): array {
        $kkNumber = null;
        $address = null;
        $rt = null;
        $rw = null;
        $lingkungan = null;
        $postalCode = null;

        $tableHeaderIndex = $this->findTableHeader($lines);

        $headerEnd = $tableHeaderIndex ?? count($lines);

        for ($i = 0; $i < $headerEnd; $i++) {
            $line = $lines[$i];

            if ($postalCode === null && preg_match('/(?:kode\s*pos|kodepos|pos)\s*[:.\s]?\s*(\d{5})/i', $line, $m)) {
                $postalCode = $m[1];
            }

            [
                $key,
                $value,
            ] = $this->splitKeyValue($line);

            /*
             * Address continuation.
             *
             * Hanya diterima ketika:
             * - address sudah ditemukan
             * - bukan label lain
             * - tidak terlihat seperti data anggota
             */
            if ($key === null) {
                if ($rt === null && $rw === null) {
                    [
                        $parsedRt,
                        $parsedRw,
                    ] = $this->parseRtRwPair(
                        $line,
                    );

                    if ($parsedRt !== null || $parsedRw !== null) {
                        $rt = $parsedRt;
                        $rw = $parsedRw;

                        continue;
                    }
                }

                if (
                    $address !== null
                    && $this->isAddressContinuation($line)
                ) {
                    $address = trim(
                        $address.' '.$line,
                    );
                }

                continue;
            }

            switch ($key) {
                case 'NOMOR_KK':
                case 'NO_KK':
                case 'NO_KK_DOT':
                case 'NO_DOT':
                case 'NO':
                case 'NOMOR_KARTU_KELUARGA':
                    $nextLine = (
                        $kkNumber === null
                        && isset($lines[$i + 1])
                    )
                        ? $lines[$i + 1]
                        : null;

                    $candidate = $this->extractKkNumber(
                        $value,
                        $nextLine,
                        $warnings,
                    );

                    if ($candidate === null) {
                        break;
                    }

                    if ($kkNumber === null) {
                        $kkNumber = $candidate;
                    } elseif ($kkNumber !== $candidate) {
                        $warnings[] =
                            'Nomor KK ganda tidak konsisten: '
                            .$candidate
                            .' diabaikan.';
                    }

                    break;

                case 'ALAMAT':
                    $candidate = trim(
                        (string) ($value ?? ''),
                    );

                    if ($candidate === '') {
                        break;
                    }

                    if ($address === null) {
                        $address = $candidate;
                    } elseif ($candidate !== $address) {
                        $warnings[] =
                            'Label ALAMAT ditemukan lebih dari satu kali. '
                            .'Nilai pertama dipertahankan.';
                    }

                    break;

                case 'KODE_POS':
                case 'KODEPOS':
                    $candidate = preg_replace('/\D/', '', (string) ($value ?? ''));
                    if (preg_match('/^\d{5}$/', $candidate)) {
                        $postalCode ??= $candidate;
                    }

                    break;

                case 'RT_RW':
                    [
                        $parsedRt,
                        $parsedRw,
                    ] = $this->parseRtRwPair(
                        $value,
                    );

                    if (
                        $parsedRt === null
                        && $parsedRw === null
                    ) {
                        $warnings[] =
                            'Nilai RT/RW ditemukan tetapi formatnya tidak dikenali.';
                        break;
                    }

                    if (
                        $rt !== null
                        && $parsedRt !== null
                        && $rt !== $parsedRt
                    ) {
                        $warnings[] =
                            'Nilai RT berbeda ditemukan pada dokumen. '
                            .'Nilai pertama dipertahankan.';
                    }

                    if (
                        $rw !== null
                        && $parsedRw !== null
                        && $rw !== $parsedRw
                    ) {
                        $warnings[] =
                            'Nilai RW berbeda ditemukan pada dokumen. '
                            .'Nilai pertama dipertahankan.';
                    }

                    $rt ??= $parsedRt;
                    $rw ??= $parsedRw;

                    break;

                case 'RT':
                    // Periksa jika line RT juga memuat RW (contoh: RT.001 RW.004 atau RT 01 / RW 04)
                    [
                        $parsedRt,
                        $parsedRw,
                    ] = $this->parseRtRwPair('RT '.$value);

                    if ($parsedRt !== null || $parsedRw !== null) {
                        if ($rt !== null && $parsedRt !== null && $rt !== $parsedRt) {
                            $warnings[] =
                                'Nilai RT berbeda ditemukan pada dokumen. '
                                .'Nilai pertama dipertahankan.';
                        }

                        if ($rw !== null && $parsedRw !== null && $rw !== $parsedRw) {
                            $warnings[] =
                                'Nilai RW berbeda ditemukan pada dokumen. '
                                .'Nilai pertama dipertahankan.';
                        }

                        $rt ??= $parsedRt;
                        $rw ??= $parsedRw;

                        break;
                    }

                    $candidate = $this->extractAreaNumber(
                        $value,
                    );

                    if ($candidate === null) {
                        break;
                    }

                    if ($rt === null) {
                        $rt = $candidate;
                    } elseif ($rt !== $candidate) {
                        $warnings[] =
                            'Nilai RT ganda tidak konsisten. '
                            .'Nilai pertama dipertahankan.';
                    }

                    break;

                case 'RW':
                    $candidate = $this->extractAreaNumber(
                        $value,
                    );

                    if ($candidate === null) {
                        break;
                    }

                    if ($rw === null) {
                        $rw = $candidate;
                    } elseif ($rw !== $candidate) {
                        $warnings[] =
                            'Nilai RW ganda tidak konsisten. '
                            .'Nilai pertama dipertahankan.';
                    }

                    break;

                case 'LINGKUNGAN':
                    $candidate = trim(
                        (string) ($value ?? ''),
                    );

                    if ($candidate === '') {
                        break;
                    }

                    if ($lingkungan === null) {
                        $lingkungan =
                            $this->normalizeLingkungan(
                                $candidate,
                            );
                    } elseif (
                        $lingkungan !==
                        $this->normalizeLingkungan($candidate)
                    ) {
                        $warnings[] =
                            'Nilai lingkungan ganda tidak konsisten. '
                            .'Nilai pertama dipertahankan.';
                    }

                    break;
            }
        }

        return [
            $kkNumber,
            $address,
            $rt,
            $rw,
            $lingkungan,
            $postalCode,
        ];
    }

    /**
     * Tentukan apakah sebuah line merupakan kelanjutan alamat.
     */
    private function isAddressContinuation(
        string $line,
    ): bool {
        if (str_contains($line, ':')) {
            return false;
        }

        $tokens = $this->tokenize($line);

        foreach ($tokens as [, $norm]) {
            if (
                $norm === 'NIK'
                || $norm === 'N1K'
                || preg_match(
                    '/^\d{16}$/',
                    $norm,
                ) === 1
            ) {
                return false;
            }
        }

        /*
         * Jangan memasukkan label header lain ke alamat.
         */
        $upper = strtoupper(trim($line));

        foreach (
            self::HEADER_KEYS as $label
        ) {
            if (
                $upper === $label
                || str_starts_with(
                    $upper,
                    $label.' ',
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kenali label header.
     *
     * Lebih toleran terhadap:
     *
     * NOMOR KARTU KELUARGA:
     * NOMOR KARTU KELUARGA 123...
     * NOMOR KK : 123...
     * NO KK 123...
     * RT/RW : 001/004
     */
    private function splitKeyValue(
        string $line,
    ): array {
        $upper = strtoupper(
            trim($line),
        );

        /*
         * Bersihkan variasi OCR umum pada label.
         *
         * Hanya label, bukan nilai data.
         */
        $upper = str_replace(
            [
                'N0MOR',
                'N0.',
                'N0 ',
            ],
            [
                'NOMOR',
                'NO.',
                'NO ',
            ],
            $upper,
        );

        foreach (
            self::HEADER_KEYS as $key => $token
        ) {
            $pattern = preg_quote(
                $token,
                '/',
            );

            /*
             * Label + colon atau dot.
             *
             * Toleran terhadap OCR run-on di mana satu karakter
             * menyatu ke label sebelum titik dua, contoh:
             *   Lingkunganl:
             *   LINGKUNGANl:
             *   lingkunganx:
             *   RT.001
             *
             * Satu karakter alfanumerik opsional diperbolehkan
             * antara token dan colon. Ini aman karena hanya
             * label yang diperiksa; nilai nilainya tetap diekstrak
             * utuh.
             */
            if (
                preg_match(
                    '/^'.$pattern.
                    '\\s*[A-Z0-9]?\\s*[:.]\\s*(.*)$/u',
                    $upper,
                    $matches,
                ) === 1
            ) {
                return [
                    $key,
                    trim($matches[1]),
                ];
            }

            /*
             * Label tanpa colon.
             *
             * Pastikan ada boundary whitespace agar
             * "RT" tidak memakan kata lain.
             */
            if (
                preg_match(
                    '/^'.$pattern.'(?:\\s+)(.*)$/u',
                    $upper,
                    $matches,
                ) === 1
            ) {
                return [
                    $key,
                    trim($matches[1]),
                ];
            }

            /*
             * Label berdiri sendiri.
             */
            if ($upper === $token) {
                return [
                    $key,
                    '',
                ];
            }
        }

        return [
            null,
            null,
        ];
    }

    /**
     * Ekstrak nomor KK 16 digit.
     *
     * Mendukung OCR yang memecah nomor:
     *
     * 3207122801160001
     *
     * atau:
     *
     * 3207 1228 0116 0001
     *
     * atau karakter OCR tertentu yang salah terbaca.
     */
    private function extractKkNumber(
        string $value,
        ?string $nextLine,
        array &$warnings,
    ): ?string {
        $sources = [
            $value,
        ];

        if (
            trim($value) === ''
            && $nextLine !== null
        ) {
            $sources[] = trim($nextLine);
        }

        foreach ($sources as $source) {
            $candidate = $this->extractSixteenDigitNumber(
                $source,
                true,
            );

            if ($candidate !== null) {
                return $candidate;
            }
        }

        if (trim($value) !== '') {
            $warnings[] =
                'Nomor KK tidak terbaca pada baris: '
                .$value;
        }

        return null;
    }

    /**
     * Normalisasi karakter OCR menjadi digit angka hanya pada konteks numerik.
     *
     * Mapping minimal:
     * O / o -> 0
     * I / l / | / ! -> 1
     * S / s -> 5
     * B -> 8
     */
    private function normalizeNumericDigits(string $value): string
    {
        return strtr(
            $value,
            [
                'O' => '0',
                'o' => '0',
                'I' => '1',
                'l' => '1',
                '|' => '1',
                '!' => '1',
                'S' => '5',
                's' => '5',
                'B' => '8',
            ]
        );
    }

    /**
     * Cari angka 16 digit dalam teks.
     */
    private function extractSixteenDigitNumber(
        string $value,
        bool $allowOcrDigitCorrection = true,
    ): ?string {
        $source = strtoupper($value);

        /*
         * Hilangkan separator yang lazim muncul di nomor.
         */
        $compact = preg_replace(
            '/[\s.\-\/|!]+/u',
            '',
            $source,
        ) ?? $source;

        /*
         * Coba tanpa koreksi OCR terlebih dahulu.
         * Ini paling aman.
         */
        if (
            preg_match(
                '/(?<!\d)(\d{16})(?!\d)/',
                $compact,
                $matches,
            ) === 1
        ) {
            return $matches[1];
        }

        if (! $allowOcrDigitCorrection) {
            return null;
        }

        /*
         * Koreksi karakter OCR hanya pada konteks nomor.
         *
         * Contoh:
         * O -> 0
         * I/L/|/! -> 1
         * S -> 5
         * B -> 8
         */
        $corrected = $this->normalizeNumericDigits($compact);

        if (
            preg_match(
                '/(?<!\d)(\d{16})(?!\d)/',
                $corrected,
                $matches,
            ) === 1
        ) {
            return $matches[1];
        }

        /*
         * Jika OCR memisahkan nomor menjadi kelompok:
         *
         * 3207 1228 0116 0001
         *
         * sudah ditangani oleh compact.
         */
        return null;
    }

    /**
     * Parse:
     *
     * 001/004
     * 001 - 004
     * 001 / 004
     * 001 004
     *
     * Return sudah dinormalisasi menjadi:
     *
     * RT = 01
     * RW = 04
     */
    private function parseRtRwPair(
        ?string $value,
    ): array {
        if (
            $value === null
            || trim($value) === ''
        ) {
            return [
                null,
                null,
            ];
        }

        $source = trim($value);

        /*
         * Format eksplisit gabungan:
         * RT 001 RW 004
         * RT.001 RW.004
         * RT: 001 RW: 004
         * RT 01 / RW 04
         * RT.001 / RW.004
         */
        if (
            preg_match(
                '/RT\s*[:.]?\s*(\d{1,3})\s*(?:[-\/]|dan|,|\s)?\s*RW\s*[:.]?\s*(\d{1,3})/i',
                $source,
                $matches,
            ) === 1
        ) {
            return [
                $this->normalizeAreaNumber($matches[1]),
                $this->normalizeAreaNumber($matches[2]),
            ];
        }

        /*
         * Format RT/RW: 001/004 atau RT/RW 001 / 004
         */
        if (
            preg_match(
                '/RT\s*\/\s*RW\s*[:.]?\s*(\d{1,3})\s*[-\/]\s*(\d{1,3})/i',
                $source,
                $matches,
            ) === 1
        ) {
            return [
                $this->normalizeAreaNumber($matches[1]),
                $this->normalizeAreaNumber($matches[2]),
            ];
        }

        /*
         * Format umum tanpa prefix:
         * 001/004
         * 001-004
         * 001 / 004
         * 001/004 Kabupaten/Kota: GARUT
         */
        if (
            preg_match(
                '/^(\d{1,3})\s*[-\/]\s*(\d{1,3})(?!\d)/',
                $source,
                $matches,
            ) === 1
        ) {
            return [
                $this->normalizeAreaNumber($matches[1]),
                $this->normalizeAreaNumber($matches[2]),
            ];
        }

        /*
         * Format OCR yang kehilangan slash:
         * 001 004
         */
        if (
            preg_match(
                '/^(\d{1,3})\s+(\d{1,3})$/',
                $source,
                $matches,
            ) === 1
        ) {
            return [
                $this->normalizeAreaNumber($matches[1]),
                $this->normalizeAreaNumber($matches[2]),
            ];
        }

        /*
         * Format jika hanya RT atau RW yang ditemukan secara eksplisit:
         */
        $hasRt = preg_match('/RT\s*[:.]?\s*(\d{1,3})/i', $source, $rtMatch) === 1;
        $hasRw = preg_match('/RW\s*[:.]?\s*(\d{1,3})/i', $source, $rwMatch) === 1;

        if ($hasRt && $hasRw) {
            return [
                $this->normalizeAreaNumber($rtMatch[1]),
                $this->normalizeAreaNumber($rwMatch[1]),
            ];
        }

        if ($hasRt) {
            return [
                $this->normalizeAreaNumber($rtMatch[1]),
                null,
            ];
        }

        if ($hasRw) {
            return [
                null,
                $this->normalizeAreaNumber($rwMatch[1]),
            ];
        }

        return [
            null,
            null,
        ];
    }

    /**
     * Ambil nomor area dari value RT/RW.
     */
    private function extractAreaNumber(
        ?string $value,
    ): ?string {
        if (
            $value === null
            || trim($value) === ''
        ) {
            return null;
        }

        /*
         * Coba koreksi sederhana jika OCR membaca
         * angka dengan karakter mirip digit.
         */
        $source = $this->normalizeNumericDigits(
            strtoupper(trim($value))
        );

        if (
            preg_match(
                '/\b\d{1,3}\b/',
                $source,
                $matches,
            ) === 1 ||
            preg_match(
                '/\d{1,3}/',
                $source,
                $matches,
            ) === 1
        ) {
            return $this->normalizeAreaNumber(
                $matches[0],
            );
        }

        return null;
    }

    /**
     * RT/RW di database/domain menggunakan format 3 digit canonical: 001, 002, 004, dst.
     *
     * Parser mengembalikan format canonical yang sama tanpa memangkas leading zero.
     */
    private function normalizeAreaNumber(
        string $value,
    ): ?string {
        $digits = preg_replace(
            '/\D/',
            '',
            $value,
        );

        if (
            $digits === null
            || $digits === ''
            || strlen($digits) > 3
        ) {
            return null;
        }

        $number = (int) $digits;

        if ($number < 0 || $number > 999) {
            return null;
        }

        return str_pad(
            (string) $number,
            3,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * Normalisasi nilai lingkungan.
     *
     * Contoh:
     *
     * Lingkungan I
     * lingkungan 1
     * LINGKUNGAN I
     *
     * tetap menjadi label yang dapat dibaca operator.
     */
    private function normalizeLingkungan(
        string $value,
    ): string {
        $value = trim(
            preg_replace(
                '/\s+/u',
                ' ',
                $value,
            ) ?? $value,
        );

        if (
            preg_match(
                '/^LINGKUNGAN\s+(.+)$/i',
                $value,
                $matches,
            ) === 1
        ) {
            return trim(
                'Lingkungan '.$matches[1],
            );
        }

        return $value;
    }

    /**
     * Parse tabel anggota.
     *
     * Parser sekarang mendukung row yang terpecah:
     *
     * 1 BUDI ... NIK ...
     *     LAKI-LAKI TANETE ...
     *
     * Baris tersebut akan digabung sebelum diproses.
     *
     * @param  array<int, string>  $lines
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $validationErrors
     * @return array<int, ParsedResident>
     */
    private function parseMembers(
        array $lines,
        float $confidence,
        bool $lowConfidence,
        array &$warnings,
        array &$validationErrors,
    ): array {
        $headerIndex = $this->findTableHeader(
            $lines,
        );

        if ($headerIndex === null) {
            $warnings[] =
                'Baris tabel anggota tidak terdeteksi.';

            return [];
        }

        $members = [];
        $seenNiks = [];

        $currentTokens = null;
        $currentOrdinal = null;

        $flushCurrent = function () use (
            &$currentTokens,
            &$currentOrdinal,
            &$members,
            &$seenNiks,
            $confidence,
            $lowConfidence,
            &$warnings,
            &$validationErrors,
        ): void {
            if (
                $currentTokens === null
                || $currentTokens === []
            ) {
                return;
            }

            $nikIndex = $this->findNikIndex(
                $currentTokens,
            );

            if ($nikIndex === null) {
                $warnings[] =
                    'Baris anggota tidak dapat diuraikan '
                    .'karena NIK tidak terbaca.';

                $currentTokens = null;
                $currentOrdinal = null;

                return;
            }

            $nik = $currentTokens[$nikIndex][1];

            if (isset($seenNiks[$nik])) {
                $warnings[] =
                    'NIK duplikat diabaikan: '.$nik;

                $currentTokens = null;
                $currentOrdinal = null;

                return;
            }

            $seenNiks[$nik] = true;

            $rowWarnings = [];
            $rowValidationErrors = [];

            $parsedRow = $this->parseMemberRow(
                $currentTokens,
                $nikIndex,
                $confidence,
                $lowConfidence,
                $rowWarnings,
                $rowValidationErrors,
                $currentOrdinal ?? (count($members) + 1),
            );

            // Abaikan baris semu/garbage yang tidak memiliki nama, gender, maupun tanggal lahir
            if (
                ($parsedRow->nama === null || trim($parsedRow->nama, ' -_') === '')
                && $parsedRow->gender === null
                && $parsedRow->birthDate === null
            ) {
                $currentTokens = null;
                $currentOrdinal = null;

                return;
            }

            foreach ($rowWarnings as $w) {
                $warnings[] = $w;
            }
            foreach ($rowValidationErrors as $v) {
                $validationErrors[] = $v;
            }

            $members[] = $parsedRow;

            $currentTokens = null;
            $currentOrdinal = null;
        };

        $count = count($lines);

        for (
            $i = $headerIndex + 1;
            $i < $count;
            $i++
        ) {
            $line = $lines[$i];

            /*
             * Stop jika sudah masuk bagian yang jelas bukan
             * tabel anggota.
             */
            if ($this->isEndOfMemberTable($line)) {
                $flushCurrent();

                break;
            }

            $tokens = $this->tokenize($line);

            if ($tokens === []) {
                continue;
            }

            $nikIndex = $this->findNikIndex(
                $tokens,
            );

            if ($nikIndex !== null) {
                /*
                 * NIK baru = row baru.
                 */
                $flushCurrent();

                $currentTokens = $tokens;
                $currentOrdinal = $i - $headerIndex;

                continue;
            }

            /*
             * Jika belum ada row aktif, abaikan.
             */
            if ($currentTokens === null) {
                if (
                    $this->looksLikeMemberRow($line)
                ) {
                    $warnings[] =
                        'Baris anggota tidak dapat '
                        .'diuraikan (NIK tidak terbaca): '
                        .$line;
                }

                continue;
            }

            /*
             * Baris yang diawali nomor urut (mis. "2") tetapi tidak
             * memiliki NIK 16 digit adalah anggota baru yang gagal OCR,
             * bukan kelanjutan baris sebelumnya. Jangan gabungkan —
             * laporkan sebagai tidak terbaca agar tidak menelan data.
             */
            if (
                $tokens !== []
                && preg_match('/^\d{1,2}$/', $tokens[0][1]) === 1
            ) {
                $flushCurrent();

                if ($this->looksLikeMemberRow($line)) {
                    $warnings[] =
                        'Baris anggota tidak dapat diuraikan (NIK tidak terbaca): '
                        .$line;
                }

                continue;
            }

            /*
             * Row lanjutan.
             *
             * Gabungkan hanya jika bukan header/footer.
             */
            if (
                ! $this->looksLikeTableNoise($line)
            ) {
                $currentTokens = array_merge(
                    $currentTokens,
                    $tokens,
                );
            }
        }

        /*
         * Flush row terakhir.
         */
        $flushCurrent();

        /*
         * Two-Table KK: Row stitching dengan Tabel 2 (Status Perkawinan & Hubungan Keluarga).
         */
        $table2Data = $this->parseTableTwo($lines, $warnings, $headerIndex);
        if ($table2Data !== []) {
            $stitched = [];
            foreach ($members as $idx => $member) {
                $ordinal = $idx + 1;
                $t2 = $table2Data[$ordinal] ?? null;

                $marital = $member->maritalStatus ?? ($t2['marital'] ?? null);
                $relation = $member->familyRelation ?? ($t2['relation'] ?? null);

                $stitched[] = new ParsedResident(
                    nama: $member->nama,
                    nik: $member->nik,
                    gender: $member->gender,
                    birthPlace: $member->birthPlace,
                    birthDate: $member->birthDate,
                    religion: $member->religion,
                    education: $member->education,
                    occupation: $member->occupation,
                    maritalStatus: $marital,
                    familyRelation: $relation,
                    confidence: $member->confidence,
                    lowConfidence: $member->lowConfidence,
                );
            }
            $members = $stitched;
        }

        return $members;
    }

    /**
     * Parse Tabel 2 Kartu Keluarga (Status Perkawinan, Hubungan Keluarga, dll.)
     *
     * @param  array<int, string>  $lines
     * @param  array<int, string>  $warnings
     * @return array<int, array{marital: ?string, relation: ?string}>
     */
    private function parseTableTwo(
        array $lines,
        array &$warnings,
        ?int $headerIndex = null,
    ): array {
        $table2Index = null;
        $count = count($lines);
        $startIndex = $headerIndex !== null ? $headerIndex + 1 : 0;

        for ($i = $startIndex; $i < $count; $i++) {
            if ($this->looksLikeMemberRow($lines[$i])) {
                continue;
            }

            $curr = strtoupper($lines[$i]);
            $next = isset($lines[$i + 1]) ? strtoupper($lines[$i + 1]) : '';
            $combined = $curr.' '.$next;

            $hasStatus = str_contains($curr, 'STATUS PERKAWINAN') || (str_contains($curr, 'PERKAWINAN') && str_contains($curr, 'STATUS'));
            $hasHubungan = str_contains($curr, 'STATUS HUBUNGAN')
                || str_contains($curr, 'HUBUNGAN DALAM KELUARGA')
                || str_contains($curr, 'HUBUNGAN KELUARGA')
                || str_contains($curr, 'STATUS HUBUNGAN DALAM')
                || str_contains($curr, 'HUBUNGAN')
                || str_contains($curr, 'SHDK');
            $hasAyahIbu = str_contains($curr, 'NAMA AYAH')
                || str_contains($curr, 'NAMA IBU')
                || str_contains($curr, 'KEWARGANEGARAAN')
                || str_contains($curr, 'DOKUMEN IMIGRASI')
                || str_contains($curr, 'PASPOR')
                || str_contains($curr, 'KITAS')
                || str_contains($curr, 'KITAP');

            if ($hasHubungan || ($hasStatus && $hasAyahIbu) || ($hasStatus && $hasHubungan) || ($hasStatus && str_contains($combined, 'HUBUNGAN'))) {
                $lastHeaderLine = $i;
                if (isset($lines[$i + 1]) && $this->looksLikeTableNoise($lines[$i + 1])) {
                    $lastHeaderLine = $i + 1;
                }
                if (isset($lines[$i + 2]) && $this->looksLikeTableNoise($lines[$i + 2])) {
                    $lastHeaderLine = $i + 2;
                }
                $table2Index = $lastHeaderLine;
                break;
            }
        }

        /*
         * Fallback jika header Tabel 2 sama sekali tidak terbaca / hilang:
         * Deteksi baris pertama yang berisi vocabulary status perkawinan / hubungan keluarga.
         */
        if ($table2Index === null) {
            for ($i = $startIndex; $i < $count; $i++) {
                if ($this->looksLikeMemberRow($lines[$i])) {
                    continue;
                }

                $tokens = $this->tokenize($lines[$i]);
                if ($tokens === []) {
                    continue;
                }
                $usedRanges = [];
                $relMatch = $this->findVocabularyMatch($tokens, 0, $this->phrasesFor('relation'), $usedRanges);
                $marMatch = $this->findVocabularyMatch($tokens, 0, $this->phrasesFor('marital'), $usedRanges);
                if ($relMatch !== null || $marMatch !== null) {
                    $table2Index = max(0, $i - 1);
                    break;
                }
            }
        }

        if ($table2Index === null) {
            return [];
        }

        $table2Rows = [];
        $currentOrdinal = 1;

        for ($i = $table2Index + 1; $i < $count; $i++) {
            $line = $lines[$i];

            if ($this->isEndOfMemberTable($line)) {
                break;
            }

            if ($this->looksLikeTableNoise($line)) {
                continue;
            }

            $tokens = $this->tokenize($line);
            if ($tokens === []) {
                continue;
            }

            $ordinal = null;
            if (preg_match('/^\d{1,2}$/', $tokens[0][1]) === 1) {
                $ordinal = (int) $tokens[0][1];
            } else {
                $ordinal = $currentOrdinal;
            }

            $usedRanges = [];
            $maritalMatch = $this->findVocabularyMatch($tokens, 0, $this->phrasesFor('marital'), $usedRanges);
            if ($maritalMatch) {
                $usedRanges[] = [$maritalMatch[1], $maritalMatch[1] + $maritalMatch[2] - 1];
            }

            $relationMatch = $this->findVocabularyMatch($tokens, 0, $this->phrasesFor('relation'), $usedRanges);

            $marital = $maritalMatch ? $maritalMatch[0] : null;
            $relation = $relationMatch ? $relationMatch[0] : null;

            if ($marital !== null || $relation !== null) {
                $table2Rows[$ordinal] = [
                    'marital' => $marital,
                    'relation' => $relation,
                ];
                $currentOrdinal = $ordinal + 1;
            }
        }

        return $table2Rows;
    }

    /**
     * Deteksi apakah dokumen yang di-scan merupakan KTP (Kartu Tanda Penduduk).
     *
     * @param  array<int, string>  $lines
     */
    private function isKtpDocument(array $lines): bool
    {
        $hasKtpHeader = false;
        $hasKkHeader = false;

        foreach ($lines as $line) {
            $upper = strtoupper($line);

            if (
                str_contains($upper, 'PROVINSI')
                || str_contains($upper, 'KARTU TANDA PENDUDUK')
                || str_contains($upper, 'REPUBLIK INDONESIA')
                || str_contains($upper, 'GOL. DARAH')
                || str_contains($upper, 'BERLAKU HINGGA')
                || (str_contains($upper, 'KEWARGANEGARAAN') && str_contains($upper, 'WNI'))
            ) {
                $hasKtpHeader = true;
            }

            if (
                str_contains($upper, 'KARTU KELUARGA')
                || str_contains($upper, 'NOMOR KK')
                || str_contains($upper, 'NO KK')
                || str_contains($upper, 'NOMOR KARTU KELUARGA')
                || str_contains($upper, 'KEPALA KELUARGA')
            ) {
                $hasKkHeader = true;
            }
        }

        return $hasKtpHeader && ! $hasKkHeader;
    }

    /**
     * Parse dokumen KTP menjadi ParsedOcrResult.
     *
     * @param  array<int, string>  $lines
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $validationErrors
     */
    private function parseKtp(
        array $lines,
        float $confidence,
        bool $lowConfidence,
        array &$warnings,
        array &$validationErrors,
        float $startedAt,
    ): ParsedOcrResult {
        $nik = null;
        $nama = null;
        $birthPlace = null;
        $birthDate = null;
        $gender = null;
        $address = null;
        $rt = null;
        $rw = null;
        $kelDesa = null;
        $kecamatan = null;
        $religion = null;
        $marital = null;
        $occupation = null;

        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            $upper = strtoupper(trim($line));

            // 1. NIK
            if ($nik === null && (str_starts_with($upper, 'NIK') || preg_match('/\b\d{16}\b/', $upper))) {
                $candidate = $this->extractSixteenDigitNumber($line, true);
                if ($candidate !== null) {
                    $nik = $candidate;

                    continue;
                }
            }

            // 2. NAMA
            if ($nama === null && preg_match('/^NAMA\s*[:.]?\s*(.*)$/i', $line, $m)) {
                $val = trim($m[1]);
                if ($val !== '') {
                    $nama = $val;

                    continue;
                } elseif (isset($lines[$i + 1])) {
                    $nama = trim($lines[$i + 1]);

                    continue;
                }
            }

            // 3. TEMPAT / TGL LAHIR
            if (($birthPlace === null || $birthDate === null) && (str_contains($upper, 'TEMPAT') || str_contains($upper, 'LAHIR') || str_contains($upper, 'TGL LAHIR'))) {
                $val = preg_replace('/^(?:TEMPAT|TGL|TANGGAL|TEMPAT\/TGL|TEMPAT\/TANGGAL)\s*LAHIR\s*[:.]?\s*/i', '', $line) ?? $line;

                $dob = $this->extractBirthDate($val);
                if ($dob !== null) {
                    $birthDate = $dob;
                }

                $parts = explode(',', $val);
                if (count($parts) > 1) {
                    $birthPlace = trim($parts[0]);
                    if ($birthDate === null) {
                        $birthDate = $this->extractBirthDate(trim($parts[1]));
                    }
                } else {
                    $placeOnly = preg_replace('/\b\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4}\b/', '', $val) ?? $val;
                    $placeOnly = $this->removeKnownGenderWords(trim($placeOnly, ' :,-.'));
                    if ($placeOnly !== '') {
                        $birthPlace = $placeOnly;
                    }
                }

                continue;
            }

            // 4. JENIS KELAMIN
            if ($gender === null && str_contains($upper, 'JENIS KELAMIN')) {
                $cleanGender = preg_replace('/[_\s\-]+/u', '', $upper) ?? $upper;
                if (str_contains($cleanGender, 'PEREMPUAN') || str_contains($cleanGender, 'PEREMP4N') || str_contains($cleanGender, 'PEREMPU4N')) {
                    $gender = 'PEREMPUAN';
                } elseif (str_contains($cleanGender, 'LAKILAKI') || str_contains($cleanGender, 'LAKI')) {
                    $gender = 'LAKI_LAKI';
                }

                continue;
            }

            // 5. ALAMAT
            if ($address === null && preg_match('/^ALAMAT\s*[:.]?\s*(.*)$/i', $line, $m)) {
                $val = trim($m[1]);
                if ($val !== '') {
                    $address = $val;

                    continue;
                }
            }

            // 6. RT/RW
            if (($rt === null || $rw === null) && (str_contains($upper, 'RT/RW') || str_contains($upper, 'RT.') || str_starts_with($upper, 'RT '))) {
                [$parsedRt, $parsedRw] = $this->parseRtRwPair($line);
                $rt ??= $parsedRt;
                $rw ??= $parsedRw;

                continue;
            }

            // 7. KEL / DESA
            if ($kelDesa === null && preg_match('#^(?:KEL\s*/\s*DESA|KELURAHAN|KEL|DESA)\s*[:.]?\s*(.*)$#i', $line, $m)) {
                $val = trim($m[1]);
                if ($val !== '') {
                    $kelDesa = $val;

                    continue;
                }
            }

            // 8. KECAMATAN
            if ($kecamatan === null && preg_match('/^KECAMATAN\s*[:.]?\s*(.*)$/i', $line, $m)) {
                $val = trim($m[1]);
                if ($val !== '') {
                    $kecamatan = $val;

                    continue;
                }
            }

            // 9. AGAMA
            if ($religion === null && str_contains($upper, 'AGAMA')) {
                $val = preg_replace('/^AGAMA\s*[:.]?\s*/i', '', $line) ?? $line;
                $tokens = $this->tokenize($val);
                $match = $this->findVocabularyMatch($tokens, 0, $this->phrasesFor('religion'), []);
                if ($match !== null) {
                    $religion = $match[0];

                    continue;
                }
            }

            // 10. STATUS PERKAWINAN
            if ($marital === null && (str_contains($upper, 'STATUS PERKAWINAN') || str_contains($upper, 'PERKAWINAN'))) {
                $val = preg_replace('/^(?:STATUS\s+)?PERKAWINAN\s*[:.]?\s*/i', '', $line) ?? $line;
                $tokens = $this->tokenize($val);
                $match = $this->findVocabularyMatch($tokens, 0, $this->phrasesFor('marital'), []);
                if ($match !== null) {
                    $marital = $match[0];

                    continue;
                }
            }

            // 11. PEKERJAAN
            if ($occupation === null && str_contains($upper, 'PEKERJAAN')) {
                $val = preg_replace('/^(?:JENIS\s+)?PEKERJAAN\s*[:.]?\s*/i', '', $line) ?? $line;
                $tokens = $this->tokenize($val);
                $match = $this->findVocabularyMatch($tokens, 0, $this->phrasesFor('occupation'), []);
                if ($match !== null) {
                    $occupation = $match[0];

                    continue;
                } elseif (trim($val) !== '') {
                    $occupation = trim($val);

                    continue;
                }
            }
        }

        if ($nik === null) {
            $validationErrors[] = 'NIK tidak ditemukan pada dokumen KTP.';
        }
        if ($nama === null) {
            $validationErrors[] = 'Nama tidak ditemukan pada dokumen KTP.';
        }

        $members = [];
        if ($nik !== null || $nama !== null) {
            $members[] = new ParsedResident(
                nama: $this->sanitizeName($nama),
                nik: $nik ?? '',
                gender: $gender,
                birthPlace: $this->sanitizeBirthPlace($birthPlace),
                birthDate: $birthDate,
                religion: $religion,
                education: null,
                occupation: $occupation,
                maritalStatus: $marital,
                familyRelation: 'KEPALA_KELUARGA',
                confidence: $confidence,
                lowConfidence: $lowConfidence,
            );
        }

        $this->log(
            $confidence,
            $lowConfidence,
            count($members),
            count($warnings),
            $startedAt,
        );

        return new ParsedOcrResult(
            confidence: $confidence,
            lowConfidence: $lowConfidence,
            kkNumber: null,
            address: $address,
            rt: $rt,
            rw: $rw,
            lingkungan: $kelDesa ?? $kecamatan,
            members: $members,
            warnings: $warnings,
            validationErrors: $validationErrors,
            durationMs: $this->elapsedMs($startedAt),
        );
    }

    /**
     * Cari header tabel anggota.
     *
     * Mendukung:
     *
     * NO NAMA NIK ...
     *
     * dan header yang terpecah:
     *
     * NO NAMA
     * NIK JENIS KELAMIN ...
     */
    private function findTableHeader(
        array $lines,
    ): ?int {
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $tokens = $this->tokenize(
                $lines[$i],
            );

            $norms = array_column(
                $tokens,
                1,
            );

            $hasNik =
                in_array('NIK', $norms, true)
                || in_array('N1K', $norms, true);

            if (! $hasNik) {
                continue;
            }

            $hasNama =
                in_array('NAMA', $norms, true);

            if (! $hasNama && $i > 0) {
                $previous = array_column(
                    $this->tokenize(
                        $lines[$i - 1],
                    ),
                    1,
                );

                $hasNama =
                    in_array(
                        'NAMA',
                        $previous,
                        true,
                    );
            }

            if (! $hasNama && $i + 1 < $count) {
                $next = array_column(
                    $this->tokenize(
                        $lines[$i + 1],
                    ),
                    1,
                );

                $hasNama =
                    in_array(
                        'NAMA',
                        $next,
                        true,
                    );
            }

            if ($hasNama) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Parse satu row anggota.
     *
     * Urutan standar KK:
     *
     * NAMA
     * NIK
     * JENIS KELAMIN
     * TEMPAT LAHIR
     * TANGGAL LAHIR
     * AGAMA
     * PENDIDIKAN
     * PEKERJAAN
     * STATUS PERKAWINAN
     * HUBUNGAN DALAM KELUARGA
     *
     * Field yang hilang tidak ditebak.
     */
    private function parseMemberRow(
        array $tokens,
        int $nikIndex,
        float $confidence,
        bool $lowConfidence,
        array &$warnings,
        array &$validationErrors,
        int $ordinal,
    ): ParsedResident {
        /*
         * Nama berada sebelum NIK.
         */
        $nameTokens = $this->stripRowNumber(
            array_slice(
                $tokens,
                0,
                $nikIndex,
            ),
        );

        $nama = $nameTokens === []
            ? null
            : $this->sanitizeName(
                trim(
                    implode(
                        ' ',
                        array_column(
                            $nameTokens,
                            0,
                        ),
                    ),
                ),
            );

        if ($nama === null || $nama === '') {
            $nama = null;

            $validationErrors[] =
                'Nama tidak terbaca pada anggota ke-'
                .$ordinal.'.';
        }

        $nik = $tokens[$nikIndex][1];

        /*
         * Bagian setelah NIK.
         */
        $afterNik = array_slice(
            $tokens,
            $nikIndex + 1,
        );

        $afterNikCount = count(
            $afterNik,
        );

        /*
         * ============================================================
         * GENDER
         * ============================================================
         */
        $gender = null;
        $genderIndex = null;
        $genderLength = 1;

        for (
            $i = 0;
            $i < $afterNikCount;
            $i++
        ) {
            $norm = $afterNik[$i][1];
            $cleanGender = preg_replace('/[_\s\-]+/u', '', $norm) ?? $norm;

            if (
                $cleanGender === 'PEREMPUAN'
                || $cleanGender === 'PEREMP4N'
                || $cleanGender === 'PEREMPU4N'
            ) {
                $gender = 'PEREMPUAN';
                $genderIndex = $i;

                break;
            }

            if (
                $cleanGender === 'LAKILAKI'
                || $cleanGender === 'LAKI-LAKI'
                || $cleanGender === 'LAKI_LAKI'
            ) {
                $gender = 'LAKI_LAKI';
                $genderIndex = $i;

                break;
            }

            if (
                $cleanGender === 'LAKI'
                && isset($afterNik[$i + 1])
                && (preg_replace('/[_\s\-]+/u', '', $afterNik[$i + 1][1]) === 'LAKI')
            ) {
                $gender = 'LAKI_LAKI';
                $genderIndex = $i;
                $genderLength = 2;

                break;
            }
        }

        /*
         * ============================================================
         * TANGGAL LAHIR
         * ============================================================
         *
         * Dicari independen dari gender supaya tetap bisa terbaca
         * jika kolom gender gagal OCR.
         */
        $birthDate = null;
        $dateIndex = null;

        for (
            $i = 0;
            $i < $afterNikCount;
            $i++
        ) {
            $raw = $afterNik[$i][0];
            $norm = $afterNik[$i][1];

            $date = $this->extractBirthDate(
                $norm,
            );

            if ($date === null) {
                continue;
            }

            $dateIndex = $i;
            $birthDate = $date;

            break;
        }

        /*
         * Kalau ada token yang terlihat seperti tanggal tetapi
         * tidak valid, catat sebagai validation error.
         */
        if ($dateIndex === null) {
            foreach (
                $afterNik as [$raw, $norm]
            ) {
                $normDigits = $this->normalizeNumericDigits($norm);
                if (
                    preg_match(
                        '/^\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4}$/',
                        $normDigits,
                    ) === 1
                ) {
                    $validationErrors[] =
                        'Tanggal lahir tidak valid pada anggota ke-'
                        .$ordinal.': '.$raw;

                    break;
                }
            }
        }

        /*
         * ============================================================
         * TEMPAT LAHIR
         * ============================================================
         */
        $birthPlace = null;

        if (
            $dateIndex !== null
            && $genderIndex !== null
            && $dateIndex > $genderIndex + $genderLength
        ) {
            $between = array_slice(
                $afterNik,
                $genderIndex + $genderLength,
                $dateIndex
                    - $genderIndex
                    - $genderLength,
            );

            $birthPlace =
                $this->joinRawTokens(
                    $between,
                );
        } elseif (
            $dateIndex !== null
            && $genderIndex === null
            && $dateIndex > 0
        ) {
            /*
             * Gender tidak terbaca.
             *
             * Kita masih dapat mengambil kandidat tempat lahir
             * dari token sebelum tanggal.
             */
            $between = array_slice(
                $afterNik,
                0,
                $dateIndex,
            );

            $birthPlace =
                $this->sanitizeBirthPlace(
                    $this->removeKnownGenderWords(
                        $this->joinRawTokens(
                            $between,
                        ),
                    ),
                );
        }

        $birthPlace = $this->sanitizeBirthPlace($birthPlace);

        if ($birthPlace === '') {
            $birthPlace = null;
        }

        /*
         * ============================================================
         * FIELD VOCABULARY
         * ============================================================
         *
         * Masing-masing dicari secara independen setelah tanggal.
         * Ini lebih tahan jika satu kolom OCR hilang.
         */
        $searchStart = $dateIndex !== null
            ? $dateIndex + 1
            : (
                $genderIndex !== null
                    ? $genderIndex + $genderLength
                    : 0
            );

        $assignments = [
            'religion' => null,
            'education' => null,
            'occupation' => null,
            'marital' => null,
            'relation' => null,
        ];

        $usedRanges = [];

        foreach (
            array_keys($assignments) as $field
        ) {
            $match = $this->findVocabularyMatch(
                $afterNik,
                $searchStart,
                $this->phrasesFor($field),
                $usedRanges,
            );

            if ($match === null) {
                continue;
            }

            [
                $label,
                $start,
                $length,
            ] = $match;

            $assignments[$field] = $label;

            $usedRanges[] = [
                $start,
                $start + $length - 1,
            ];
        }

        /*
         * Validasi nama/NIK minimal.
         */
        if ($nik === '') {
            $validationErrors[] =
                'NIK kosong pada anggota ke-'.$ordinal.'.';
        }

        /*
         * Beri warning jika field penting tidak terbaca.
         *
         * Tidak semua field dibuat validation error karena
         * operator masih dapat melengkapinya secara manual.
         */
        $missing = [];

        if ($gender === null) {
            $missing[] = 'jenis kelamin';
        }

        if ($birthDate === null) {
            $missing[] = 'tanggal lahir';
        }

        if ($assignments['religion'] === null) {
            $missing[] = 'agama';
        }

        if ($assignments['familyRelation'] ?? null) {
            // Tidak digunakan; compatibility guard.
        }

        if ($assignments['relation'] === null) {
            $missing[] = 'hubungan keluarga';
        }

        if ($missing !== []) {
            $warnings[] =
                'Anggota ke-'.$ordinal
                .' belum terbaca lengkap: '
                .implode(', ', $missing)
                .'.';
        }

        return new ParsedResident(
            nama: $nama,
            nik: $nik,
            gender: $gender,
            birthPlace: $birthPlace,
            birthDate: $birthDate,
            religion: $assignments['religion'],
            education: $assignments['education'],
            occupation: $assignments['occupation'],
            maritalStatus: $assignments['marital'],
            familyRelation: $assignments['relation'],
            confidence: $confidence,
            lowConfidence: $lowConfidence,
        );
    }

    /**
     * Ambil tanggal lahir dari token.
     */
    private function extractBirthDate(
        string $value,
    ): ?string {
        $normalized = $this->normalizeNumericDigits($value);

        if (
            preg_match(
                '/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/',
                $normalized,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return $this->normalizeBirthDate(
            $matches[1],
            $matches[2],
            $matches[3],
        );
    }

    /**
     * Hapus kata gender jika gender tidak berhasil dipisahkan.
     */
    private function removeKnownGenderWords(
        string $value,
    ): string {
        $value = preg_replace(
            '/\bLAKI[\s_\-]*LAKI\b/i',
            '',
            $value,
        ) ?? $value;

        $value = preg_replace(
            '/\bLAKILAKI\b/i',
            '',
            $value,
        ) ?? $value;

        $value = preg_replace(
            '/\bPEREMP(?:UAN|4N|U4N)\b/i',
            '',
            $value,
        ) ?? $value;

        return trim(
            preg_replace(
                '/\s+/u',
                ' ',
                $value,
            ) ?? $value,
        );
    }

    /**
     * Gabungkan raw token tanpa kehilangan teks asli.
     *
     * @param  array<int, array{0:string,1:string}>  $tokens
     */
    private function joinRawTokens(
        array $tokens,
    ): string {
        return trim(
            implode(
                ' ',
                array_column(
                    $tokens,
                    0,
                ),
            ),
        );
    }

    /**
     * Cari vocabulary phrase secara fleksibel.
     *
     * Tidak harus langsung setelah field sebelumnya.
     *
     * @param  array<int, array{0:string,1:string}>  $tokens
     * @param  array<string, array<int,string>>  $phrases
     * @param  array<int, array{0:int,1:int}>  $usedRanges
     * @return array{0:string,1:int,2:int}|null
     */
    private function findVocabularyMatch(
        array $tokens,
        int $start,
        array $phrases,
        array $usedRanges,
    ): ?array {
        $best = null;
        $bestLength = 0;
        $bestStart = null;

        $count = count($tokens);

        for (
            $i = max(0, $start);
            $i < $count;
            $i++
        ) {
            if (
                $this->rangeOverlaps(
                    $i,
                    $i,
                    $usedRanges,
                )
            ) {
                continue;
            }

            foreach (
                $phrases as $key => $val
            ) {
                $label = is_int($key) ? $val[0] : $key;
                $sequence = is_int($key) ? $val[1] : $val;

                $length = count($sequence);

                if (
                    $length <= $bestLength
                    || $i + $length > $count
                ) {
                    continue;
                }

                if (
                    $this->rangeOverlaps(
                        $i,
                        $i + $length - 1,
                        $usedRanges,
                    )
                ) {
                    continue;
                }

                $matched = true;

                for (
                    $k = 0;
                    $k < $length;
                    $k++
                ) {
                    if (
                        ! $this->tokensEquivalent(
                            $tokens[$i + $k][1],
                            $sequence[$k],
                        )
                    ) {
                        $matched = false;

                        break;
                    }
                }

                if (! $matched) {
                    continue;
                }

                $best = $label;
                $bestLength = $length;
                $bestStart = $i;
            }
        }

        if (
            $best === null
            || $bestStart === null
        ) {
            return null;
        }

        return [
            $best,
            $bestStart,
            $bestLength,
        ];
    }

    /**
     * Toleransi kecil terhadap OCR typo.
     *
     * Tidak melakukan fuzzy matching agresif.
     */
    private function tokensEquivalent(
        string $actual,
        string $expected,
    ): bool {
        if ($actual === $expected) {
            return true;
        }

        $aliases = [
            'N1K' => 'NIK',
            'KAT0LIK' => 'KATOLIK',
            'KR1STEN' => 'KRISTEN',
            'H1NDU' => 'HINDU',
            'BUDHHA' => 'BUDDHA',
            'KAW1N' => 'KAWIN',
            'KAWN' => 'KAWIN',
            'BELUMKAWN' => 'BELUMKAWIN',
            '1STRI' => 'ISTRI',
            'ISTERI' => 'ISTRI',
            'AN4K' => 'ANAK',
            'ANAK2' => 'ANAK',
            'ANAK-' => 'ANAK',
            'KEPALAKEUARGA' => 'KEPALAKELUARGA',
            'KEPALAKEL' => 'KEPALAKELUARGA',
            'ORANGTUA' => 'ORANG TUA',
            'SI' => 'S1',
            'S-I' => 'S1',
            'S-II' => 'S2',
            'S-III' => 'S3',
            'D-I' => 'D1',
            'D-II' => 'D2',
            'D-III' => 'D3',
            'D-IV' => 'D4',
            'D-1' => 'D1',
            'D-2' => 'D2',
            'D-3' => 'D3',
            'D-4' => 'D4',
            'PELAJARIMAHASISWA' => 'PELAJAR/MAHASISWA',
        ];

        return ($aliases[$actual] ?? $actual)
            === $expected;
    }

    /**
     * Cek apakah range token bertabrakan dengan match lain.
     *
     * @param  array<int, array{0:int,1:int}>  $ranges
     */
    private function rangeOverlaps(
        int $start,
        int $end,
        array $ranges,
    ): bool {
        foreach ($ranges as [$rangeStart, $rangeEnd]) {
            if (
                $start <= $rangeEnd
                && $end >= $rangeStart
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vocabulary untuk field tertentu.
     */
    private function phrasesFor(
        string $field,
    ): array {
        return match ($field) {
            'religion' => self::RELIGIONS,
            'education' => self::EDUCATIONS,
            'occupation' => self::OCCUPATIONS,
            'marital' => self::MARITAL_STATUSES,
            'relation' => self::FAMILY_RELATIONS,
        };
    }

    /**
     * Compatibility helper untuk kode lama.
     *
     * Dipertahankan supaya tidak merusak pemanggil internal
     * atau test yang masih menggunakan behavior longest-match.
     *
     * @param  array<int, array{0:string,1:string}>  $tokens
     * @param  array<string, array<int,string>>  $phrases
     * @return array{0:string,1:int}|null
     */
    private function longestMatch(
        array $tokens,
        int $start,
        array $phrases,
    ): ?array {
        $match = $this->findVocabularyMatch(
            $tokens,
            $start,
            $phrases,
            [],
        );

        if ($match === null) {
            return null;
        }

        return [
            $match[0],
            $match[2],
        ];
    }

    /**
     * Tokenize line menjadi:
     *
     * [
     *     [raw, normalized],
     *     ...
     * ]
     *
     * Raw dipertahankan untuk display.
     * Normalized dipakai untuk matching.
     *
     * @return array<int, array{0:string,1:string}>
     */
    private function tokenize(
        string $line,
    ): array {
        $tokens = [];

        foreach (
            preg_split(
                '/\s+/u',
                trim($line),
            ) ?: [] as $raw
        ) {
            if ($raw === '') {
                continue;
            }

            $norm = strtoupper(
                trim($raw),
            );

            /*
             * Hapus punctuation di ujung token.
             */
            $norm = preg_replace(
                '/^[^\pL\pN]+|[^\pL\pN]+$/u',
                '',
                $norm,
            ) ?? $norm;

            /*
             * Normalisasi typo header NIK.
             *
             * Jangan melakukan O->0 secara global karena
             * nama orang bisa mengandung huruf O.
             */
            if ($norm === 'N1K') {
                $norm = 'NIK';
            }

            /*
             * LAKI - LAKI dengan separator OCR.
             */
            if ($norm === 'LAKI_ LAKI' || $norm === 'LAKI_LAKI') {
                $norm = 'LAKI-LAKI';
            }

            /*
             * Jika token berupa 16-digit angka atau variasi OCR digit NIK,
             * lakukan normalisasi angka pada token tersebut.
             */
            $compact = preg_replace('/[\s.\-\/|!]+/u', '', $norm) ?? $norm;
            $digitCandidate = $this->normalizeNumericDigits($compact);
            if (preg_match('/^\d{16}$/', $digitCandidate) === 1) {
                $norm = $digitCandidate;
            }

            $tokens[] = [
                $raw,
                $norm,
            ];
        }

        return $this->mergeNikRuns(
            $tokens,
        );
    }

    /**
     * Gabungkan angka yang terpecah menjadi NIK 16 digit.
     *
     * Contoh:
     *
     * 3207 1228 0116 0001
     *
     * menjadi:
     *
     * 3207122801160001
     *
     * @param  array<int, array{0:string,1:string}>  $tokens
     * @return array<int, array{0:string,1:string}>
     */
    private function mergeNikRuns(
        array $tokens,
    ): array {
        $merged = [];
        $count = count($tokens);

        for (
            $i = 0;
            $i < $count;
            $i++
        ) {
            $rawRun = '';
            $normRun = '';
            $j = $i;

            while (
                $j < $count
                && preg_match(
                    '/^[0-9OolI|!SsBb.\-\/]{1,8}$/',
                    $tokens[$j][1],
                ) === 1
            ) {
                $rawRun .= $tokens[$j][0];
                $compactToken = preg_replace('/[\s.\-\/|!]+/u', '', $tokens[$j][1]) ?? $tokens[$j][1];
                $normRun .= $this->normalizeNumericDigits($compactToken);
                $j++;

                if (strlen($normRun) >= 16) {
                    break;
                }
            }

            /*
             * Minimal dua token agar angka biasa seperti "1"
             * tidak dianggap sebagai NIK.
             */
            if (
                $j - $i >= 2
                && preg_match('/^\d{16}$/', $normRun) === 1
            ) {
                $merged[] = [
                    $rawRun,
                    $normRun,
                ];

                $i = $j - 1;

                continue;
            }

            $merged[] = $tokens[$i];
        }

        return $merged;
    }

    /**
     * Cari NIK pertama pada token.
     */
    private function findNikIndex(
        array $tokens,
    ): ?int {
        foreach (
            $tokens as $i => [, $norm]
        ) {
            if (
                preg_match(
                    '/^\d{16}$/',
                    $norm,
                ) === 1
            ) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Apakah line terlihat seperti baris anggota tetapi
     * NIK-nya gagal dibaca.
     */
    private function looksLikeMemberRow(
        string $line,
    ): bool {
        return preg_match(
            '/\d{2,}/',
            $line,
        ) === 1;
    }

    /**
     * Hentikan parsing tabel ketika menemukan footer/header
     * yang jelas bukan data anggota.
     */
    private function isEndOfMemberTable(
        string $line,
    ): bool {
        $upper = strtoupper(
            trim($line),
        );

        $endMarkers = [
            'KETERANGAN',
            'PENJELASAN',
            'CATATAN',
            'DITETAPKAN DI',
            'DITETAPKAN',
            'DIKELUARKAN DI',
            'DIKELUARKAN',
            'PADA TANGGAL',
            'MENGETAHUI',
            'KEPALA DINAS',
            'KEPALA DESA',
            'LURAH',
            'CAMAT',
            'TANDA TANGAN',
            'TANDATANGAN',
            'LEMBAR I',
            'LEMBAR II',
            'LEMBAR III',
            'LEMBAR IV',
            'TGL PENERBITAN',
            'TANGGAL PENERBITAN',
            'TANGGAL CETAK',
            'I. KEPALA KELUARGA',
            'II. RT/RW',
            'III. DESA/KELURAHAN',
            'IV. KECAMATAN',
        ];

        foreach ($endMarkers as $marker) {
            if (str_starts_with($upper, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitasi nama hasil OCR berbasis field:
     * - Hilangkan noise dekoratif / batas kolom tabel di awal/akhir: ), (, |, :, ;, -, /, \, _, ., `, ~, !
     * - Pertahankan karakter sah di tengah nama (spasi, titik inisial, tanda petik/apostrof, tanda hubung).
     */
    private function sanitizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $cleaned = preg_replace('/^[)\(|:;\-\/\\\\_\.\`\~\!0-9\s]+/u', '', $name) ?? $name;
        $cleaned = preg_replace('/[)\(|:;\-\/\\\\_\.\`\~\!\s]+$/u', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+/u', ' ', trim($cleaned)) ?? trim($cleaned);

        return $cleaned !== '' ? $cleaned : null;
    }

    /**
     * Sanitasi tempat lahir:
     * - Hilangkan noise batas tabel/garis di awal dan akhir string.
     */
    private function sanitizeBirthPlace(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $cleaned = $this->removeKnownGenderWords($value);
        $cleaned = preg_replace('/^[)\(|:;\-\/\\\\_\.\`\~\!0-9\s]+/u', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/[)\(|:;\-\/\\\\_\.\`\~\!\s]+$/u', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+/u', ' ', trim($cleaned)) ?? trim($cleaned);

        return $cleaned !== '' ? $cleaned : null;
    }

    /**
     * Abaikan noise yang sering muncul di sekitar tabel.
     */
    private function looksLikeTableNoise(
        string $line,
    ): bool {
        $upper = strtoupper(
            trim($line),
        );

        if ($upper === '') {
            return true;
        }

        // Abaikan baris penomoran kolom tabel: (1) (2) (3) ... atau 1 2 3 4 5 6 7 8 9 10
        if (preg_match('/^(?:\(?\d{1,2}\)?[\s|\-_.:]*){3,}$/', $upper) === 1) {
            return true;
        }

        $noise = [
            'NO',
            'NAMA',
            'NIK',
            'JENIS KELAMIN',
            'TEMPAT LAHIR',
            'TANGGAL LAHIR',
            'AGAMA',
            'PENDIDIKAN',
            'PEKERJAAN',
            'STATUS PERKAWINAN',
            'STATUS HUBUNGAN DALAM KELUARGA',
            'HUBUNGAN DALAM KELUARGA',
            'HUBUNGAN KELUARGA',
            'STATUS HUBUNGAN',
            'HUBUNGAN',
            'SHDK',
            'NAMA AYAH',
            'NAMA IBU',
            'KEWARGANEGARAAN',
            'DOKUMEN IMIGRASI',
            'PASPOR',
            'KITAS',
            'KITAP',
            'NO. PASPOR',
            'NO. KITAS',
            'NO. KITAP',
        ];

        foreach ($noise as $marker) {
            if ($upper === $marker) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hapus row number:
     *
     * 1 BUDI SANTOSO
     *
     * menjadi:
     *
     * BUDI SANTOSO
     *
     * @param  array<int, array{0:string,1:string}>  $tokens
     * @return array<int, array{0:string,1:string}>
     */
    private function stripRowNumber(
        array $tokens,
    ): array {
        while (
            $tokens !== []
            && preg_match(
                '/^\d{1,2}$/',
                $tokens[0][1],
            ) === 1
        ) {
            array_shift($tokens);
        }

        return $tokens;
    }

    /**
     * Normalize tanggal lahir.
     *
     * Menghasilkan:
     *
     * Y-m-d
     *
     * atau null jika tidak valid.
     */
    private function normalizeBirthDate(
        string $day,
        string $month,
        string $year,
    ): ?string {
        $day = (int) $day;
        $month = (int) $month;

        if (
            $day < 1
            || $day > 31
            || $month < 1
            || $month > 12
        ) {
            return null;
        }

        if (strlen($year) === 2) {
            $year = (
                (int) $year
            ) < 70
                ? '20'.$year
                : '19'.$year;
        }

        $year = (int) $year;

        $currentYear = (int) now()->year;

        if (
            $year < 1900
            || $year > $currentYear
        ) {
            return null;
        }

        if (! checkdate(
            $month,
            $day,
            $year,
        )) {
            return null;
        }

        return sprintf(
            '%04d-%02d-%02d',
            $year,
            $month,
            $day,
        );
    }

    /**
     * Validasi konsistensi hasil anggota.
     *
     * Tidak memblokir data.
     * Hanya memberikan informasi kepada operator.
     *
     * @param  array<int, ParsedResident>  $members
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $validationErrors
     */
    private function validateMemberConsistency(
        array $members,
        array &$warnings,
        array &$validationErrors,
    ): void {
        if ($members === []) {
            return;
        }

        $headCount = 0;

        foreach ($members as $member) {
            if (
                $member->familyRelation
                === 'KEPALA_KELUARGA'
            ) {
                $headCount++;
            }
        }

        if ($headCount === 0) {
            $warnings[] =
                'Kepala Keluarga belum terbaca dari hasil OCR.';
        } elseif ($headCount > 1) {
            $validationErrors[] =
                'OCR menemukan lebih dari satu anggota '
                .'dengan hubungan Kepala Keluarga. '
                .'Periksa kembali sebelum menyimpan.';
        }

        /*
         * NIK sudah dijamin unik oleh parseMembers().
         *
         * Pemeriksaan ini hanya sebagai defensive guard.
         */
        $niks = array_filter(
            array_map(
                static fn (
                    ParsedResident $member
                ): ?string => $member->nik,
                $members,
            ),
        );

        if (
            count($niks)
            !== count(array_unique($niks))
        ) {
            $validationErrors[] =
                'Terdapat NIK duplikat pada hasil OCR.';
        }
    }

    /**
     * Pipeline-stage log.
     */
    private function log(
        float $confidence,
        bool $lowConfidence,
        int $memberCount,
        int $warningCount,
        float $startedAt,
    ): void {
        Log::info(
            'OCR parsing '
            .($lowConfidence
                ? 'low_confidence'
                : 'success'),
            [
                'pipeline_stage' => 'parse',
                'outcome' => $lowConfidence
                    ? 'low_confidence'
                    : 'success',
                'duration_ms' => round(
                    $this->elapsedMs(
                        $startedAt,
                    ),
                    2,
                ),
                'confidence' => $confidence,
                'member_count' => $memberCount,
                'warning_count' => $warningCount,
            ],
        );
    }

    /**
     * Durasi parser dalam milidetik.
     */
    private function elapsedMs(
        float $startedAt,
    ): float {
        return (
            microtime(true)
            - $startedAt
        ) * 1000;
    }
}
