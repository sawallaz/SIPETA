<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Rule-based OCR text parser (Phase 5.5, .ai/ocr.md §4.5–§4.7, ADR-017).
 *
 * Converts the raw text produced by the Phase 5.4 engine into a structured
 * ParsedOcrResult containing only project-defined fields (FR-OCR-02):
 *
 *   KK level  : nomor KK, alamat, RT, RW, lingkungan
 *   member    : nama, NIK, jenis kelamin, tempat lahir, tanggal lahir,
 *               agama, pendidikan, pekerjaan, status perkawinan, status
 *               hubungan keluarga
 *
 * Strategy (rule-based, deterministic, offline — ADR-017):
 *
 *   1. Header key/value scan — "NOMOR KARTU KELUARGA", "ALAMAT", "RT/RW",
 *      "RT", "RW", "LINGKUNGAN" labels with ':' or space separators; the
 *      address may wrap to the following lines.
 *   2. Member-table parse — locate the table header row (the "NIK" column
 *      line), then read each following row that carries a valid 16-digit
 *      NIK; the remaining row tokens are attributed in column order
 *      (gender, birth place, birth date, religion, education, occupation,
 *      marital status, family relation) by longest-match against the
 *      curated vocabularies below.
 *   3. Required-field validation (.ai/ocr.md §4.7) — KK number and at least
 *      one member NIK are required; dates must parse and fall in a sane
 *      range. Problems land in validationErrors, never exceptions.
 *
 * Graceful degradation is deliberate: missing values stay null, duplicated
 * labels keep the first occurrence, malformed rows are skipped with a
 * warning, and low-confidence text flags the whole result (FR-OCR-04).
 *
 * The engine currently exposes only an aggregated mean confidence, so
 * field-level confidence (minimum word confidence, .ai/ocr.md §4.4) is
 * approximated by carrying the aggregate onto every extracted member.
 */
final class OcrParsingService
{
    /** Header labels, longest first so "NOMOR KARTU KELUARGA" wins over
     * "NOMOR KK" and "RT/RW" over "RT". */
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

    /** Religion vocabulary (.ai/ocr.md §4.5). */
    private const RELIGIONS = [
        'ISLAM' => ['ISLAM'],
        'KRISTEN' => ['KRISTEN'],
        'KATOLIK' => ['KATOLIK'],
        'HINDU' => ['HINDU'],
        'BUDDHA' => ['BUDDHA'],
        'KONGHUCU' => ['KONGHUCU'],
    ];

    /** Education labels as printed on a KK card (normalized, longest first). */
    private const EDUCATIONS = [
        'TIDAK/BELUM SEKOLAH' => ['TIDAK/BELUM', 'SEKOLAH'],
        'BELUM TAMAT SD/SEDERAJAT' => ['BELUM', 'TAMAT', 'SD/SEDERAJAT'],
        'TAMAT SD/SEDERAJAT' => ['TAMAT', 'SD/SEDERAJAT'],
        'AKADEMI/DIPLOMA III/SARJANA MUDA' => ['AKADEMI/DIPLOMA', 'III/SARJANA', 'MUDA'],
        'DIPLOMA IV/STRATA I' => ['DIPLOMA', 'IV/STRATA', 'I'],
        'DIPLOMA I/II' => ['DIPLOMA', 'I/II'],
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

    /** Occupation labels, matching the project's occupation masters. */
    private const OCCUPATIONS = [
        'PEGAWAI NEGERI SIPIL' => ['PEGAWAI', 'NEGERI', 'SIPIL'],
        'IBU RUMAH TANGGA' => ['IBU', 'RUMAH', 'TANGGA'],
        'BURUH HARIAN LEPAS' => ['BURUH', 'HARIAN', 'LEPAS'],
        'KARYAWAN SWASTA' => ['KARYAWAN', 'SWASTA'],
        'PELAJAR/MAHASISWA' => ['PELAJAR/MAHASISWA'],
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

    /** Marital status vocabulary (.ai/ocr.md §4.5). */
    private const MARITAL_STATUSES = [
        'BELUM_KAWIN' => ['BELUM', 'KAWIN'],
        'CERAI_HIDUP' => ['CERAI', 'HIDUP'],
        'CERAI_MATI' => ['CERAI', 'MATI'],
        'KAWIN' => ['KAWIN'],
    ];

    /** Family relation vocabulary (.ai/ocr.md §4.5). */
    private const FAMILY_RELATIONS = [
        'KEPALA_KELUARGA' => ['KEPALA', 'KELUARGA'],
        'ORANG_TUA' => ['ORANG', 'TUA'],
        'FAMILI_LAIN' => ['FAMILI', 'LAIN'],
        'MENANTU' => ['MENANTU'],
        'CUCU' => ['CUCU'],
        'MERTUA' => ['MERTUA'],
        'ISTRI' => ['ISTRI'],
        'ANAK' => ['ANAK'],
        'LAINNYA' => ['LAINNYA'],
    ];

    /**
     * Parse raw OCR text into a structured result.
     *
     * @param  string  $rawText  raw extracted text (OcrResult::rawText)
     * @param  float  $confidence  engine aggregate confidence (0–100)
     */
    public function parse(string $rawText, float $confidence = 0.0): ParsedOcrResult
    {
        $startedAt = microtime(true);

        $threshold = (float) config('ocr.confidence_threshold', 70);
        $lowConfidence = $confidence < $threshold;

        $warnings = [];
        $validationErrors = [];

        if ($confidence < 30) {
            $warnings[] = 'Gambar tidak terbaca (confidence sangat rendah)';
        }

        $lines = $this->normalizeLines($rawText);

        if ($lines === []) {
            $warnings[] = 'OCR tidak menghasilkan teks (gambar kosong atau tidak terbaca)';
            $validationErrors[] = 'OCR tidak menemukan NIK';

            $this->log($confidence, $lowConfidence, 0, 0, $startedAt);

            return new ParsedOcrResult(
                $confidence,
                $lowConfidence,
                null,
                null,
                null,
                null,
                null,
                [],
                $warnings,
                $validationErrors,
                $this->elapsedMs($startedAt),
            );
        }

        [$kkNumber, $address, $rt, $rw, $lingkungan] = $this->parseHeader($lines, $warnings);
        $members = $this->parseMembers($lines, $confidence, $lowConfidence, $warnings, $validationErrors);

        // Required-field validation (.ai/ocr.md §4.7): the KK number and at
        // least one member NIK are the minimum a KK record needs.
        if ($kkNumber === null) {
            $validationErrors[] = 'Nomor KK tidak ditemukan';
        }

        if ($members === []) {
            $validationErrors[] = 'OCR tidak menemukan NIK';
        }

        $this->log($confidence, $lowConfidence, count($members), count($warnings), $startedAt);

        return new ParsedOcrResult(
            $confidence,
            $lowConfidence,
            $kkNumber,
            $address,
            $rt,
            $rw,
            $lingkungan,
            $members,
            $warnings,
            $validationErrors,
            $this->elapsedMs($startedAt),
        );
    }

    /**
     * Split the raw text into trimmed, non-empty lines.
     *
     * @return array<int, string>
     */
    private function normalizeLines(string $rawText): array
    {
        $lines = [];

        foreach (preg_split('/\r?\n/', $rawText) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Scan the header block for the KK-level fields.
     *
     * Duplicated labels keep the first occurrence; a second occurrence that
     * disagrees is recorded as a warning. Address continuation lines (the
     * wrapped second line of a long address) are appended while they carry
     * no ':' separator and match no known label.
     *
     * @param  array<int, string>  $lines
     * @param  array<int, string>  $warnings
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string|null, 4: string|null}
     */
    private function parseHeader(array $lines, array &$warnings): array
    {
        $kkNumber = null;
        $address = null;
        $rt = null;
        $rw = null;
        $lingkungan = null;

        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            [$key, $value] = $this->splitKeyValue($line);

            // Address continuation: a non-label line immediately after
            // ALAMAT that carries no ':' separator and is not part of the
            // member table belongs to the (wrapped) address.
            if ($key === null && $address !== null) {
                if ($this->isAddressContinuation($line)) {
                    $address = trim($address.' '.$line);
                }

                continue;
            }

            switch ($key) {
                case 'NOMOR_KK':
                case 'NO_KK':
                case 'NOMOR_KARTU_KELUARGA':
                    $nextLine = $kkNumber === null && isset($lines[$i + 1]) ? $lines[$i + 1] : null;
                    $candidate = $this->extractKkNumber($value, $nextLine, $warnings);

                    if ($candidate === null) {
                        break;
                    }

                    if ($kkNumber === null) {
                        $kkNumber = $candidate;
                    } elseif ($kkNumber !== $candidate) {
                        $warnings[] = 'Nomor KK ganda tidak konsisten: '.$candidate.' (diabaikan)';
                    }
                    break;

                case 'ALAMAT':
                    $candidate = trim($value ?? '');

                    if ($address === null) {
                        $address = $candidate;
                    } elseif ($candidate !== '' && $candidate !== $address) {
                        $warnings[] = 'Label duplikat diabaikan: ALAMAT';
                    }
                    break;

                case 'RT_RW':
                    [$parsedRt, $parsedRw] = $this->parseRtRwPair($value);

                    if ($parsedRt === null && $parsedRw === null) {
                        break;
                    }

                    $conflicts = ($rt !== null && $parsedRt !== null && $rt !== $parsedRt)
                        || ($rw !== null && $parsedRw !== null && $rw !== $parsedRw);

                    $rt ??= $parsedRt;
                    $rw ??= $parsedRw;

                    if ($conflicts) {
                        $warnings[] = 'Label duplikat diabaikan: RT/RW';
                    }
                    break;

                case 'RT':
                    $candidate = $this->extractNumber($value);

                    if ($candidate === null) {
                        break;
                    }

                    if ($rt === null) {
                        $rt = $candidate;
                    } elseif ($rt !== $candidate) {
                        $warnings[] = 'Label duplikat diabaikan: RT';
                    }
                    break;

                case 'RW':
                    $candidate = $this->extractNumber($value);

                    if ($candidate === null) {
                        break;
                    }

                    if ($rw === null) {
                        $rw = $candidate;
                    } elseif ($rw !== $candidate) {
                        $warnings[] = 'Label duplikat diabaikan: RW';
                    }
                    break;

                case 'LINGKUNGAN':
                    $candidate = trim($value ?? '');

                    if ($candidate === '') {
                        break;
                    }

                    if ($lingkungan === null) {
                        $lingkungan = $candidate;
                    } elseif ($lingkungan !== $candidate) {
                        $warnings[] = 'Label duplikat diabaikan: LINGKUNGAN';
                    }
                    break;
            }
        }

        return [$kkNumber, $address, $rt, $rw, $lingkungan];
    }

    /**
     * A line may be part of a wrapped address only when it carries no ':'
     * separator (which would mark it as another label line) and contains
     * neither a "NIK" token nor a 16-digit number (which would place it in
     * the member table region).
     */
    private function isAddressContinuation(string $line): bool
    {
        if (str_contains($line, ':')) {
            return false;
        }

        foreach ($this->tokenize($line) as [, $norm]) {
            if ($norm === 'NIK' || preg_match('/^\d{16}$/', $norm) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recognize a known header label at the start of a line.
     *
     * @return array{0: string|null, 1: string|null} [key, value]; key is
     *                                               null when the line is
     *                                               not a header label line
     */
    private function splitKeyValue(string $line): array
    {
        $upper = strtoupper($line);

        foreach (self::HEADER_KEYS as $key => $token) {
            if (preg_match('/^'.preg_quote($token, '/').'\s*:\s*(.*)$/', $upper, $m) === 1) {
                return [$key, trim($m[1])];
            }

            if (preg_match('/^'.preg_quote($token, '/').'\s+(.+)$/', $upper, $m) === 1) {
                return [$key, trim($m[1])];
            }
        }

        return [null, null];
    }

    /**
     * Extract the 16-digit KK number from a header value. When the value is
     * empty the number may sit alone on the next line (a common OCR wrap).
     *
     * @param  array<int, string>  $warnings
     */
    private function extractKkNumber(string $value, ?string $nextLine, array &$warnings): ?string
    {
        $source = $value;

        if ($source === '' && $nextLine !== null && preg_match('/^\d{16}$/', trim($nextLine)) === 1) {
            $source = trim($nextLine);
        }

        $compact = preg_replace('/\s+/', '', $source) ?? $source;

        if (preg_match('/\b(\d{16})\b/', $compact, $m) === 1) {
            return $m[1];
        }

        if ($value !== '') {
            $warnings[] = 'Nomor KK tidak terbaca pada baris: '.$value;
        }

        return null;
    }

    /**
     * Parse an "RT/RW" value like "001/004" or "001 - 004".
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function parseRtRwPair(?string $value): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }

        if (preg_match('~(\d{1,3})\s*[/-]\s*(\d{1,3})~', $value, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return [null, null];
    }

    private function extractNumber(?string $value): ?string
    {
        if ($value === null || preg_match('/(\d{1,3})/', $value, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * Parse the member table: locate the header row, then read each row that
     * carries a valid 16-digit NIK. Rows without a readable NIK are skipped
     * with a warning; duplicate NIKs keep the first row only.
     *
     * @param  array<int, string>  $lines
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $validationErrors
     * @return array<int, ParsedResident>
     */
    private function parseMembers(array $lines, float $confidence, bool $lowConfidence, array &$warnings, array &$validationErrors): array
    {
        $headerIndex = $this->findTableHeader($lines);

        if ($headerIndex === null) {
            $warnings[] = 'Baris tabel anggota tidak terdeteksi';

            return [];
        }

        $members = [];
        $seenNiks = [];
        $count = count($lines);

        for ($i = $headerIndex + 1; $i < $count; $i++) {
            $tokens = $this->tokenize($lines[$i]);
            $nikIndex = $this->findNikIndex($tokens);

            if ($nikIndex === null) {
                if ($this->looksLikeMemberRow($lines[$i])) {
                    $warnings[] = 'Baris anggota tidak dapat diuraikan (NIK tidak terbaca): '.$lines[$i];
                }

                continue;
            }

            $nik = $tokens[$nikIndex][1];

            if (isset($seenNiks[$nik])) {
                $warnings[] = 'NIK duplikat diabaikan: '.$nik;

                continue;
            }

            $seenNiks[$nik] = true;

            $members[] = $this->parseMemberRow($tokens, $nikIndex, $confidence, $lowConfidence, $warnings, $validationErrors, $i - $headerIndex);
        }

        return $members;
    }

    /**
     * Locate the member-table header row: the first line whose tokens include
     * "NIK" and whose own / previous / next line includes "NAMA" (the header
     * often wraps across two OCR lines).
     *
     * @param  array<int, string>  $lines
     */
    private function findTableHeader(array $lines): ?int
    {
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $norms = array_column($this->tokenize($lines[$i]), 1);

            if (! in_array('NIK', $norms, true)) {
                continue;
            }

            $hasNama = in_array('NAMA', $norms, true);

            if (! $hasNama && $i > 0) {
                $hasNama = in_array('NAMA', array_column($this->tokenize($lines[$i - 1]), 1), true);
            }

            if (! $hasNama && $i + 1 < $count) {
                $hasNama = in_array('NAMA', array_column($this->tokenize($lines[$i + 1]), 1), true);
            }

            if ($hasNama) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Parse a single member row into a ParsedResident.
     *
     * Row layout (KK column order): nama, NIK, gender, birth place, birth
     * date, religion, education, occupation, marital status, family relation.
     * Tokens before the NIK form the name; tokens after it are attributed in
     * column order with a longest-match against the vocabularies.
     *
     * @param  array<int, array{0: string, 1: string}>  $tokens  [raw, normalized] pairs
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $validationErrors
     */
    private function parseMemberRow(array $tokens, int $nikIndex, float $confidence, bool $lowConfidence, array &$warnings, array &$validationErrors, int $ordinal): ParsedResident
    {
        $nameTokens = $this->stripRowNumber(array_slice($tokens, 0, $nikIndex));
        $nama = $nameTokens === [] ? null : implode(' ', array_column($nameTokens, 0));

        $afterNik = array_slice($tokens, $nikIndex + 1);
        $afterNikCount = count($afterNik);

        // Gender (may be "LAKI-LAKI" as one token or "LAKI LAKI" as two).
        $gender = null;
        $genderIndex = null;

        foreach ($afterNik as $i => [$raw, $norm]) {
            if (preg_match('/^PEREMPUAN$/', $norm) === 1) {
                $gender = 'PEREMPUAN';
                $genderIndex = $i;
                break;
            }

            if (preg_match('/^LAKI-?LAKI$/', $norm) === 1) {
                $gender = 'LAKI_LAKI';
                $genderIndex = $i;
                break;
            }

            if ($norm === 'LAKI' && isset($afterNik[$i + 1]) && $afterNik[$i + 1][1] === 'LAKI') {
                $gender = 'LAKI_LAKI';
                $genderIndex = $i;
                break;
            }
        }

        // Birth date: the first dd-mm-yyyy / dd/mm/yyyy token after the
        // gender (or from the start of the remainder when gender is missing).
        $birthDate = null;
        $dateIndex = null;
        $searchStart = $genderIndex !== null ? $genderIndex + 1 : 0;

        for ($i = $searchStart; $i < $afterNikCount; $i++) {
            $raw = $afterNik[$i][0];

            if (preg_match('~^(\d{1,2})[-/](\d{1,2})[-/](\d{2,4})$~', $afterNik[$i][1], $m) === 1) {
                $dateIndex = $i;
                $birthDate = $this->normalizeBirthDate($m[1], $m[2], $m[3]);

                if ($birthDate === null) {
                    $validationErrors[] = 'Tanggal lahir tidak valid pada anggota ke-'.$ordinal.': '.$raw;
                }

                break;
            }
        }

        // Birth place: the tokens strictly between gender and birth date.
        $birthPlace = null;

        if ($genderIndex !== null && $dateIndex !== null && $dateIndex > $genderIndex + 1) {
            $between = array_slice($afterNik, $genderIndex + 1, $dateIndex - $genderIndex - 1);
            $birthPlace = implode(' ', array_column($between, 0));
        }

        // Column-order attribution for the remaining tokens (after the date,
        // or after the gender / from the start when those are missing).
        $assignments = [
            'religion' => null,
            'education' => null,
            'occupation' => null,
            'marital' => null,
            'relation' => null,
        ];

        $pointer = $dateIndex !== null
            ? $dateIndex + 1
            : ($genderIndex !== null ? $genderIndex + 1 : 0);

        foreach (array_keys($assignments) as $field) {
            for ($i = $pointer; $i < $afterNikCount; $i++) {
                $match = $this->longestMatch($afterNik, $i, $this->phrasesFor($field));

                if ($match !== null) {
                    [$label, $length] = $match;
                    $assignments[$field] = $label;
                    $pointer = $i + $length;
                    break;
                }
            }
        }

        return new ParsedResident(
            nama: $nama,
            nik: $tokens[$nikIndex][1],
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
     * @return array<string, array<int, string>>
     */
    private function phrasesFor(string $field): array
    {
        return match ($field) {
            'religion' => self::RELIGIONS,
            'education' => self::EDUCATIONS,
            'occupation' => self::OCCUPATIONS,
            'marital' => self::MARITAL_STATUSES,
            'relation' => self::FAMILY_RELATIONS,
        };
    }

    /**
     * Longest matching vocabulary phrase starting at token $start.
     *
     * @param  array<int, array{0: string, 1: string}>  $tokens
     * @param  array<string, array<int, string>>  $phrases
     * @return array{0: string, 1: int}|null [label, token count]
     */
    private function longestMatch(array $tokens, int $start, array $phrases): ?array
    {
        $best = null;
        $bestLength = 0;
        $tokenCount = count($tokens);

        foreach ($phrases as $label => $sequence) {
            $length = count($sequence);

            if ($length <= $bestLength || $start + $length > $tokenCount) {
                continue;
            }

            $matched = true;

            for ($k = 0; $k < $length; $k++) {
                if ($tokens[$start + $k][1] !== $sequence[$k]) {
                    $matched = false;
                    break;
                }
            }

            if ($matched) {
                $best = $label;
                $bestLength = $length;
            }
        }

        return $best === null ? null : [$best, $bestLength];
    }

    /**
     * Tokenize a line into [raw, normalized] pairs. Normalization uppercases
     * and trims punctuation so matching tolerates OCR noise ("LAKI-LAKI,");
     * raw tokens keep the original text for display.
     *
     * Runs of pure-digit tokens totalling exactly 16 digits are merged into
     * a single NIK token (Tesseract often splits long numbers across words).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function tokenize(string $line): array
    {
        $tokens = [];

        foreach (preg_split('/\s+/', trim($line)) ?: [] as $raw) {
            if ($raw === '') {
                continue;
            }

            $norm = strtoupper($raw);
            $norm = preg_replace('/^[^\pL\pN]+|[^\pL\pN]+$/u', '', $norm) ?? $norm;
            $tokens[] = [$raw, $norm];
        }

        return $this->mergeNikRuns($tokens);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $tokens
     * @return array<int, array{0: string, 1: string}>
     */
    private function mergeNikRuns(array $tokens): array
    {
        $merged = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $rawRun = '';
            $j = $i;

            while ($j < $count && preg_match('/^\d{1,6}$/', $tokens[$j][1]) === 1) {
                $rawRun .= $tokens[$j][0];
                $j++;
            }

            if ($j - $i >= 2 && strlen($rawRun) === 16) {
                $merged[] = [$rawRun, $rawRun];
                $i = $j - 1;
            } else {
                $merged[] = $tokens[$i];
            }
        }

        return $merged;
    }

    private function findNikIndex(array $tokens): ?int
    {
        foreach ($tokens as $i => [$raw, $norm]) {
            if (preg_match('/^\d{16}$/', $norm) === 1) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Heuristic for the "unreadable member row" warning: only rows that carry
     * digits can be near-miss member rows (wrapped table headers have none).
     */
    private function looksLikeMemberRow(string $line): bool
    {
        return preg_match('/\d{2,}/', $line) === 1;
    }

    /**
     * Drop a leading row number ("1", "2", ...) from a member name.
     *
     * @param  array<int, array{0: string, 1: string}>  $tokens
     * @return array<int, array{0: string, 1: string}>
     */
    private function stripRowNumber(array $tokens): array
    {
        while ($tokens !== [] && preg_match('/^\d{1,2}$/', $tokens[0][1]) === 1) {
            array_shift($tokens);
        }

        return $tokens;
    }

    /**
     * Normalize and sanity-check a dd/mm/yyyy date; returns Y-m-d or null
     * when the date cannot be a real birth date (year 1900..current).
     */
    private function normalizeBirthDate(string $day, string $month, string $year): ?string
    {
        $day = (int) $day;
        $month = (int) $month;

        if ($month < 1 || $month > 12) {
            return null;
        }

        if (strlen($year) === 2) {
            $year = ((int) $year) < 70 ? '20'.$year : '19'.$year;
        }

        $year = (int) $year;

        if ($year < 1900 || $year > (int) now()->year) {
            return null;
        }

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Pipeline-stage log line (.ai/ocr.md §9) matching the preprocess stage.
     */
    private function log(float $confidence, bool $lowConfidence, int $memberCount, int $warningCount, float $startedAt): void
    {
        Log::info('OCR parsing '.($lowConfidence ? 'low_confidence' : 'success'), [
            'pipeline_stage' => 'parse',
            'outcome' => $lowConfidence ? 'low_confidence' : 'success',
            'duration_ms' => round($this->elapsedMs($startedAt), 2),
            'confidence' => $confidence,
            'member_count' => $memberCount,
            'warning_count' => $warningCount,
        ]);
    }

    private function elapsedMs(float $startedAt): float
    {
        return (microtime(true) - $startedAt) * 1000;
    }
}
