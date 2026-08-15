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
        'RT_RW' => 'RT/RW',
        'LINGKUNGAN' => 'LINGKUNGAN',
        'ALAMAT' => 'ALAMAT',
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

    /**
     * Pendidikan.
     */
    private const EDUCATIONS = [
        'TIDAK/BELUM SEKOLAH' => ['TIDAK/BELUM', 'SEKOLAH'],
        'BELUM TAMAT SD/SEDERAJAT' => [
            'BELUM',
            'TAMAT',
            'SD/SEDERAJAT',
        ],
        'TAMAT SD/SEDERAJAT' => [
            'TAMAT',
            'SD/SEDERAJAT',
        ],
        'AKADEMI/DIPLOMA III/SARJANA MUDA' => [
            'AKADEMI/DIPLOMA',
            'III/SARJANA',
            'MUDA',
        ],
        'DIPLOMA IV/STRATA I' => [
            'DIPLOMA',
            'IV/STRATA',
            'I',
        ],
        'DIPLOMA I/II' => [
            'DIPLOMA',
            'I/II',
        ],
        'SLTP/SEDERAJAT' => ['SLTP/SEDERAJAT'],
        'SLTA/SEDERAJAT' => ['SLTA/SEDERAJAT'],
        'SD' => ['SD'],
        'SMP' => ['SMP'],
        'SMA' => ['SMA'],
        'D1' => ['D1'],
        'D2' => ['D2'],
        'D3' => ['D3'],
        'S1' => ['S1'],
        'S2' => ['S2'],
        'S3' => ['S3'],
    ];

    /**
     * Pekerjaan.
     */
    private const OCCUPATIONS = [
        'PEGAWAI NEGERI SIPIL' => [
            'PEGAWAI',
            'NEGERI',
            'SIPIL',
        ],
        'IBU RUMAH TANGGA' => [
            'IBU',
            'RUMAH',
            'TANGGA',
        ],
        'BURUH HARIAN LEPAS' => [
            'BURUH',
            'HARIAN',
            'LEPAS',
        ],
        'KARYAWAN SWASTA' => [
            'KARYAWAN',
            'SWASTA',
        ],
        'PELAJAR/MAHASISWA' => [
            'PELAJAR/MAHASISWA',
        ],
        'PETANI' => ['PETANI'],
        'PEDAGANG' => ['PEDAGANG'],
        'NELAYAN' => ['NELAYAN'],
        'WIRASWASTA' => ['WIRASWASTA'],
        'PENSIUNAN' => ['PENSIUNAN'],
        'PELAJAR' => ['PELAJAR'],
        'MAHASISWA' => ['MAHASISWA'],
        'BURUH' => ['BURUH'],
        'TUKANG' => ['TUKANG'],
        'LAINNYA' => ['LAINNYA'],
    ];

    /**
     * Status perkawinan.
     */
    private const MARITAL_STATUSES = [
        'BELUM_KAWIN' => [
            'BELUM',
            'KAWIN',
        ],
        'CERAI_HIDUP' => [
            'CERAI',
            'HIDUP',
        ],
        'CERAI_MATI' => [
            'CERAI',
            'MATI',
        ],
        'KAWIN' => [
            'KAWIN',
        ],
    ];

    /**
     * Hubungan keluarga.
     */
    private const FAMILY_RELATIONS = [
        'KEPALA_KELUARGA' => [
            'KEPALA',
            'KELUARGA',
        ],
        'ORANG_TUA' => [
            'ORANG',
            'TUA',
        ],
        'FAMILI_LAIN' => [
            'FAMILI',
            'LAIN',
        ],
        'MENANTU' => ['MENANTU'],
        'CUCU' => ['CUCU'],
        'MERTUA' => ['MERTUA'],
        'ISTRI' => ['ISTRI'],
        'ANAK' => ['ANAK'],
        'LAINNYA' => ['LAINNYA'],
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
            array_keys(self::EDUCATIONS),
            array_keys(self::EDUCATIONS),
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
            array_keys(self::OCCUPATIONS),
            array_keys(self::OCCUPATIONS),
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

        [
            $kkNumber,
            $address,
            $rt,
            $rw,
            $lingkungan,
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

        $tableHeaderIndex = $this->findTableHeader($lines);

        $headerEnd = $tableHeaderIndex ?? count($lines);

        for ($i = 0; $i < $headerEnd; $i++) {
            $line = $lines[$i];

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
             * Label + colon.
             *
             * Toleran terhadap OCR run-on di mana satu karakter
             * menyatu ke label sebelum titik dua, contoh:
             *   Lingkunganl:
             *   LINGKUNGANl:
             *   lingkunganx:
             *
             * Satu karakter alfanumerik opsional diperbolehkan
             * antara token dan colon. Ini aman karena hanya
             * label yang diperiksa; nilai nilainya tetap diekstrak
             * utuh.
             */
            if (
                preg_match(
                    '/^'.$pattern.
                    '\\s*[A-Z0-9]?\\s*:\\s*(.*)$/u',
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
     * Cari angka 16 digit dalam teks.
     */
    private function extractSixteenDigitNumber(
        string $value,
        bool $allowOcrDigitCorrection = false,
    ): ?string {
        $source = strtoupper($value);

        /*
         * Hilangkan separator yang lazim muncul di nomor.
         */
        $compact = preg_replace(
            '/[\s.\-\/]+/u',
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
         * I/L -> 1
         * S -> 5
         * B -> 8
         */
        $corrected = strtr(
            $compact,
            [
                'O' => '0',
                'I' => '1',
                'L' => '1',
                'S' => '5',
                'B' => '8',
            ],
        );

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
         * Format eksplisit:
         *
         * RT 001 RW 004
         */
        if (
            preg_match(
                '/RT\s*[:.]?\s*(\d{1,3}).*?RW\s*[:.]?\s*(\d{1,3})/i',
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
         * Format umum:
         *
         * 001/004
         * 001-004
         * 001 / 004
         */
        if (
            preg_match(
                '/(\d{1,3})\s*[-\/]\s*(\d{1,3})/',
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
         *
         * 001 004
         */
        if (
            preg_match(
                '/^\D*(\d{1,3})\D+(\d{1,3})\D*$/',
                $source,
                $matches,
            ) === 1
        ) {
            return [
                $this->normalizeAreaNumber($matches[1]),
                $this->normalizeAreaNumber($matches[2]),
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
         * angka dengan O/I/L.
         */
        $source = strtoupper(
            trim($value),
        );

        $source = strtr(
            $source,
            [
                'O' => '0',
                'I' => '1',
                'L' => '1',
            ],
        );

        if (
            preg_match(
                '/\d{1,3}/',
                $source,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return $this->normalizeAreaNumber(
            $matches[0],
        );
    }

    /**
     * RT/RW di database menggunakan format 01, 02, dst.
     *
     * Parser mengembalikan format canonical yang sama.
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
            2,
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

            $members[] = $this->parseMemberRow(
                $currentTokens,
                $nikIndex,
                $confidence,
                $lowConfidence,
                $warnings,
                $validationErrors,
                $currentOrdinal ?? count($members) + 1,
            );

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
                $currentOrdinal =
                    $i - $headerIndex;

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

                $warnings[] =
                    'Baris anggota tidak dapat diuraikan (NIK tidak terbaca): '
                    .$line;

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

        return $members;
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
            : trim(
                implode(
                    ' ',
                    array_column(
                        $nameTokens,
                        0,
                    ),
                ),
            );

        if ($nama === '') {
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

            if ($norm === 'PEREMPUAN') {
                $gender = 'PEREMPUAN';
                $genderIndex = $i;

                break;
            }

            if (
                $norm === 'LAKI-LAKI'
                || $norm === 'LAKILAKI'
            ) {
                $gender = 'LAKI_LAKI';
                $genderIndex = $i;

                break;
            }

            if (
                $norm === 'LAKI'
                && isset($afterNik[$i + 1])
                && $afterNik[$i + 1][1] === 'LAKI'
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
                if (
                    preg_match(
                        '/^\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4}$/',
                        $norm,
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
                $this->removeKnownGenderWords(
                    $this->joinRawTokens(
                        $between,
                    ),
                );
        }

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
        if (
            preg_match(
                '/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/',
                $value,
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
            '/\bLAKI(?:-?LAKI)?\b/i',
            '',
            $value,
        ) ?? $value;

        $value = preg_replace(
            '/\bPEREMPUAN\b/i',
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
                $phrases as $label => $sequence
            ) {
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
            '1STRI' => 'ISTRI',
            'AN4K' => 'ANAK',
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
            if ($norm === 'LAKI_ LAKI') {
                $norm = 'LAKI';
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
            $j = $i;

            while (
                $j < $count
                && preg_match(
                    '/^\d{1,6}$/',
                    $tokens[$j][1],
                ) === 1
            ) {
                $rawRun .= $tokens[$j][0];
                $j++;
            }

            /*
             * Minimal dua token agar angka biasa seperti "1"
             * tidak dianggap sebagai NIK.
             */
            if (
                $j - $i >= 2
                && strlen($rawRun) === 16
            ) {
                $merged[] = [
                    $rawRun,
                    $rawRun,
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
            'MENGETAHUI',
            'KEPALA DINAS',
            'KEPALA DESA',
            'LURAH',
            'CAMAT',
        ];

        foreach ($endMarkers as $marker) {
            if (
                str_starts_with(
                    $upper,
                    $marker,
                )
            ) {
                return true;
            }
        }

        return false;
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
