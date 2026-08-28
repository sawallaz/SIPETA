<?php

namespace App\Services;

/**
 * 2D Spatial & TSV-based Kartu Keluarga Table & Member Parser.
 *
 * Menggunakan koordinat 2D (left, top, width, height, cx, cy) dari output TSV Tesseract
 * untuk merekonstruksi baris dan kolom tabel anggota secara presisi:
 *
 * 1. Deteksi Wilayah Tabel 1 & Tabel 2 dari kata kunci header.
 * 2. Header-Anchored Column Detection: menentukan batas horizontal (X) setiap kolom.
 * 3. NIK-First Row Clustering: menggunakan NIK 16 digit & nomor urut sebagai anchor baris (Y).
 * 4. Token-to-Column Mapping: memasukkan setiap kata ke kolom berdasarkan centerX.
 * 5. Strict Anti-Contamination: memastikan kolom Nama HANYA berisi nama penduduk,
 *    dan menolak kebocoran teks dari kolom Gender, Agama, Pendidikan, Pekerjaan, atau Tempat Lahir.
 * 6. Tabel 2 Row Stitching: menggabungkan Status Perkawinan, SHDK, Nama Ayah, Nama Ibu.
 */
final class SpatialTableParser
{
    /**
     * Kata kunci anti-kontaminasi untuk kolom Nama Lengkap.
     * Jika sebuah token cocok dengan daftar ini, token tersebut DITOLAK dari field Nama.
     */
    private const NAME_CONTAMINATION_WORDS = [
        // Gender
        'LAKI-LAKI', 'PEREMPUAN', 'LAKI', 'PRIA', 'WANITA',
        // Agama
        'ISLAM', 'KRISTEN', 'KATOLIK', 'HINDU', 'BUDDHA', 'KONGHUCU', 'PROTESTAN',
        // Pendidikan
        'SD', 'SMP', 'SLTP', 'SMA', 'SLTA', 'SMK', 'DIPLOMA', 'D1', 'D2', 'D3', 'D4',
        'STRATA', 'S1', 'S2', 'S3', 'SEDERAJAT', 'TIDAK/BELUM', 'BELUM TAMAT', 'TAMAT',
        'AKADEMI', 'SARJANA', 'SEKOLAH',
        // Pekerjaan
        'PETANI', 'PEKEBUN', 'KARYAWAN', 'SWASTA', 'PNS', 'TNI', 'POLRI', 'PELAJAR',
        'MAHASISWA', 'IBU RUMAH TANGGA', 'RUMAH TANGGA', 'WIRASWASTA', 'BURUH',
        'BELUM/TIDAK BEKERJA', 'MENGURUS RUMAH TANGGA', 'PEDAGANG', 'NELAYAN',
        'PENSIUNAN', 'DOKTER', 'GURU', 'PERAWAT', 'WIRAUSAHA', 'TUKANG', 'SHASTA', 'KARYAKAN',
        // Status Perkawinan
        'KAWIN', 'BELUM KAWIN', 'CERAI HIDUP', 'CERAI MATI', 'TERCATAT', 'BELUM', 'KAKIN', 'KARIN',
        // SHDK
        'KEPALA KELUARGA', 'KEPALA', 'KELUARGA', 'ISTRI', 'ANAK', 'MENANTU', 'CUCU',
        'ORANG TUA', 'MERTUA', 'FAMILI LAIN', 'FAMILI', 'LAINNYA',
        // Kewarganegaraan & Dokumen
        'WNI', 'WNA', 'PASPOR', 'KITAS', 'KITAP',
        // Common City/Place markers in sample KKs
        'BARRU', 'SEMARANG', 'MAKASSAR', 'JAKARTA', 'SURABAYA', 'BANDUNG', 'PARE-PARE',
    ];

    /**
     * Proporsi kolom default Tabel 1 (relatif 0.0 - 1.0 terhadap lebar tabel).
     */
    private const DEFAULT_T1_COLUMNS = [
        'NO' => [0.00, 0.025],
        'NAMA' => [0.025, 0.25],
        'NIK' => [0.24, 0.40],
        'GENDER' => [0.39, 0.49],
        'TEMPAT_LAHIR' => [0.48, 0.59],
        'TANGGAL_LAHIR' => [0.58, 0.69],
        'AGAMA' => [0.68, 0.76],
        'PENDIDIKAN' => [0.75, 0.86],
        'PEKERJAAN' => [0.85, 1.00],
    ];

    /**
     * Proporsi kolom default Tabel 2 (relatif 0.0 - 1.0 terhadap lebar tabel).
     */
    private const DEFAULT_T2_COLUMNS = [
        'NO' => [0.00, 0.025],
        'STATUS_KAWIN' => [0.025, 0.18],
        'TANGGAL_KAWIN' => [0.17, 0.27],
        'SHDK' => [0.26, 0.45],
        'KEWARGANEGARAAN' => [0.44, 0.58],
        'PASPOR_KITAS' => [0.57, 0.68],
        'AYAH' => [0.67, 0.85],
        'IBU' => [0.84, 1.00],
    ];

    /**
     * Parse tokens menjadi daftar ParsedResident menggunakan analisis spasial 2D.
     *
     * @param  array<int, array{text: string, conf: float, left: int, top: int, width: int, height: int, cx: float, cy: float}>  $tokens
     * @return array<int, ParsedResident>
     */
    public function parse(array $tokens, ?string $kkNumber = null, float $confidence = 80.0): array
    {
        if (empty($tokens)) {
            return [];
        }

        // 1. Deteksi bounding box Tabel 1 & Tabel 2
        $tableBounds = $this->detectTableRegions($tokens);
        if ($tableBounds === null) {
            return [];
        }

        [$t1Top, $t1Bottom, $t1Left, $t1Right, $t2Top, $t2Bottom, $t2Left, $t2Right] = $tableBounds;

        // 2. Deteksi batas horizontal kolom Tabel 1 (Header-Anchored atau Relative Proportion)
        $t1Columns = $this->resolveTableOneColumns($tokens, $t1Top, $t1Bottom, $t1Left, $t1Right);

        // 3. Cluster baris anggota pada Tabel 1 (NIK-First Anchor)
        $t1Members = $this->extractTableOneMembers($tokens, $t1Top, $t1Bottom, $t1Columns, $kkNumber, $confidence);

        if (empty($t1Members)) {
            return [];
        }

        // 4. Proses Tabel 2 jika terdeteksi (Status Kawin, SHDK, Ayah, Ibu)
        if ($t2Top !== null && $t2Bottom !== null && $t2Bottom > $t2Top) {
            $t2Columns = $this->resolveTableTwoColumns($tokens, $t2Top, $t2Bottom, $t2Left ?? $t1Left, $t2Right ?? $t1Right);
            $t2Rows = $this->extractTableTwoRows($tokens, $t2Top, $t2Bottom, $t2Columns, count($t1Members));

            // Merge Tabel 1 dan Tabel 2
            $t1Members = $this->mergeTableOneAndTwo($t1Members, $t2Rows);
        }

        return $t1Members;
    }

    /**
     * Deteksi wilayah Y dan X untuk Tabel 1 dan Tabel 2.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: ?float, 5: ?float, 6: ?float, 7: ?float}|null
     */
    private function detectTableRegions(array $tokens): ?array
    {
        $t1HeaderY = null;
        $t2HeaderY = null;
        $footerY = null;

        $minX = 999999.0;
        $maxX = 0.0;

        foreach ($tokens as $t) {
            $u = mb_strtoupper($t['text']);

            // Deteksi Header Tabel 1
            if ($t1HeaderY === null) {
                if (
                    str_contains($u, 'NAMA') && (str_contains($u, 'LENGKAP') || str_contains($u, 'KEPALA') || str_contains($u, 'NANA'))
                    || $u === 'NIK'
                    || str_contains($u, 'KELAMIN')
                    || (str_contains($u, 'TEMPAT') && str_contains($u, 'LAHIR'))
                ) {
                    $t1HeaderY = $t['cy'];
                }
            }

            // Deteksi Header Tabel 2
            if (
                str_contains($u, 'PERKAWINAN')
                || (str_contains($u, 'HUBUNGAN') && str_contains($u, 'KELUARGA'))
                || (str_contains($u, 'NAMA') && (str_contains($u, 'AYAH') || str_contains($u, 'IBU')))
                || $u === 'SHDK'
                || $u === 'KEWARGANEGARAAN'
            ) {
                if ($t2HeaderY === null || $t['cy'] < $t2HeaderY) {
                    // Hanya jika berada di bawah Tabel 1
                    if ($t1HeaderY === null || $t['cy'] > ($t1HeaderY + 60)) {
                        $t2HeaderY = $t['cy'];
                    }
                }
            }

            // Deteksi Footer (Tanda Tangan / Dikeluarkan Di)
            if (
                str_contains($u, 'DIKELUARKAN')
                || (str_contains($u, 'KEPALA') && str_contains($u, 'DINAS'))
                || (str_contains($u, 'PENCATATAN') && str_contains($u, 'SIPIL'))
                || (str_contains($u, 'TANDA') && str_contains($u, 'TANGAN'))
            ) {
                if ($footerY === null || $t['cy'] < $footerY) {
                    if ($t2HeaderY !== null && $t['cy'] > ($t2HeaderY + 40)) {
                        $footerY = $t['cy'];
                    } elseif ($t1HeaderY !== null && $t['cy'] > ($t1HeaderY + 120)) {
                        $footerY = $t['cy'];
                    }
                }
            }

            if ($t['left'] < $minX) {
                $minX = (float) $t['left'];
            }
            if ($t['left'] + $t['width'] > $maxX) {
                $maxX = (float) ($t['left'] + $t['width']);
            }
        }

        // Fallback jika header tidak ditemukan secara eksplisit: gunakan NIK pertama sebagai patokan Tabel 1
        if ($t1HeaderY === null) {
            foreach ($tokens as $t) {
                if (preg_match('/^\d{16}$/', $t['text'])) {
                    $t1HeaderY = max(0.0, $t['cy'] - 60.0);
                    break;
                }
            }
        }

        if ($t1HeaderY === null) {
            return null;
        }

        $t1Top = $t1HeaderY;
        $t1Bottom = $t2HeaderY !== null ? ($t2HeaderY - 10.0) : ($footerY !== null ? ($footerY - 10.0) : ($t1Top + 500.0));

        $t2Top = $t2HeaderY;
        $t2Bottom = $t2HeaderY !== null ? ($footerY !== null ? ($footerY - 10.0) : ($t2Top + 350.0)) : null;

        $t1Left = max(0.0, $minX);
        $t1Right = max($t1Left + 500.0, $maxX);

        return [
            $t1Top,
            $t1Bottom,
            $t1Left,
            $t1Right,
            $t2Top,
            $t2Bottom,
            $t1Left,
            $t1Right,
        ];
    }

    /**
     * Hitung batas koordinat X tiap kolom untuk Tabel 1.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    private function resolveTableOneColumns(array $tokens, float $tTop, float $tBottom, float $tLeft, float $tRight): array
    {
        $tableWidth = max(100.0, $tRight - $tLeft);
        $columns = [];

        // Inisialisasi default berdasarkan proporsi tabel
        foreach (self::DEFAULT_T1_COLUMNS as $col => [$relStart, $relEnd]) {
            $columns[$col] = [
                $tLeft + ($relStart * $tableWidth),
                $tLeft + ($relEnd * $tableWidth),
            ];
        }

        // Cari token header Tabel 1 (sekitar tTop +- 50px)
        $headerTokens = [];
        foreach ($tokens as $t) {
            if ($t['cy'] >= ($tTop - 40) && $t['cy'] <= ($tTop + 60)) {
                $headerTokens[] = $t;
            }
        }

        $anchors = [];
        foreach ($headerTokens as $ht) {
            $u = mb_strtoupper($ht['text']);
            if ($u === 'NO' || $u === 'NO.' || $u === '1') {
                $anchors['NO'] ??= $ht['cx'];
            } elseif (str_contains($u, 'NAMA') || str_contains($u, 'LENGKAP') || str_contains($u, 'NANA')) {
                $anchors['NAMA'] ??= $ht['cx'];
            } elseif ($u === 'NIK' || str_contains($u, 'INDUK') || $u === 'DIG') {
                $anchors['NIK'] ??= $ht['cx'];
            } elseif (str_contains($u, 'KELAMIN') || $u === 'JENIS') {
                $anchors['GENDER'] ??= $ht['cx'];
            } elseif (str_contains($u, 'TEMPAT')) {
                $anchors['TEMPAT_LAHIR'] ??= $ht['cx'];
            } elseif (str_contains($u, 'TANGGAL') || str_contains($u, 'TGL')) {
                $anchors['TANGGAL_LAHIR'] ??= $ht['cx'];
            } elseif ($u === 'AGAMA' || $u === 'AGANA') {
                $anchors['AGAMA'] ??= $ht['cx'];
            } elseif (str_contains($u, 'PENDIDIKAN')) {
                $anchors['PENDIDIKAN'] ??= $ht['cx'];
            } elseif (str_contains($u, 'PEKERJAAN')) {
                $anchors['PEKERJAAN'] ??= $ht['cx'];
            }
        }

        // Jika anchor NIK dan NAMA terdeteksi presisi di header, sesuaikan batas antar kolom
        if (isset($anchors['NAMA'], $anchors['NIK'])) {
            $splitNamaNik = ($anchors['NAMA'] + $anchors['NIK']) / 2;
            $columns['NAMA'][1] = $splitNamaNik;
            $columns['NIK'][0] = $splitNamaNik;
        }
        if (isset($anchors['NIK'], $anchors['GENDER'])) {
            $splitNikGender = ($anchors['NIK'] + $anchors['GENDER']) / 2;
            $columns['NIK'][1] = $splitNikGender;
            $columns['GENDER'][0] = $splitNikGender;
        }
        if (isset($anchors['GENDER'], $anchors['TEMPAT_LAHIR'])) {
            $splitGenderTempat = ($anchors['GENDER'] + $anchors['TEMPAT_LAHIR']) / 2;
            $columns['GENDER'][1] = $splitGenderTempat;
            $columns['TEMPAT_LAHIR'][0] = $splitGenderTempat;
        }

        return $columns;
    }

    /**
     * Hitung batas koordinat X tiap kolom untuk Tabel 2.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    private function resolveTableTwoColumns(array $tokens, float $tTop, float $tBottom, float $tLeft, float $tRight): array
    {
        $tableWidth = max(100.0, $tRight - $tLeft);
        $columns = [];

        foreach (self::DEFAULT_T2_COLUMNS as $col => [$relStart, $relEnd]) {
            $columns[$col] = [
                $tLeft + ($relStart * $tableWidth),
                $tLeft + ($relEnd * $tableWidth),
            ];
        }

        // Cari token header Tabel 2 (sekitar tTop +- 40px)
        $headerTokens = [];
        foreach ($tokens as $t) {
            if ($t['cy'] >= ($tTop - 35) && $t['cy'] <= ($tTop + 45)) {
                $headerTokens[] = $t;
            }
        }

        $anchors = [];
        foreach ($headerTokens as $ht) {
            $u = mb_strtoupper($ht['text']);
            if (str_contains($u, 'PERKAWINAN') && ! str_contains($u, 'TANGGAL')) {
                $anchors['STATUS_KAWIN'] ??= $ht['cx'];
            } elseif (str_contains($u, 'TANGGAL') || (str_contains($u, 'TGL') && str_contains($u, 'KAWIN'))) {
                $anchors['TANGGAL_KAWIN'] ??= $ht['cx'];
            } elseif (str_contains($u, 'HUBUNGAN') || str_contains($u, 'KELUARGA') || $u === 'SHDK') {
                $anchors['SHDK'] ??= $ht['cx'];
            } elseif (str_contains($u, 'KEWARGANEGARAAN') || $u === 'WNI') {
                $anchors['KEWARGANEGARAAN'] ??= $ht['cx'];
            } elseif (str_contains($u, 'AYAH')) {
                $anchors['AYAH'] ??= $ht['cx'];
            } elseif (str_contains($u, 'IBU')) {
                $anchors['IBU'] ??= $ht['cx'];
            }
        }

        if (isset($anchors['STATUS_KAWIN'], $anchors['TANGGAL_KAWIN'])) {
            $splitSkTk = ($anchors['STATUS_KAWIN'] + $anchors['TANGGAL_KAWIN']) / 2;
            $columns['STATUS_KAWIN'][1] = $splitSkTk;
            $columns['TANGGAL_KAWIN'][0] = $splitSkTk;
        }
        if (isset($anchors['TANGGAL_KAWIN'], $anchors['SHDK'])) {
            $splitTkShdk = ($anchors['TANGGAL_KAWIN'] + $anchors['SHDK']) / 2;
            $columns['TANGGAL_KAWIN'][1] = $splitTkShdk;
            $columns['SHDK'][0] = $splitTkShdk;
        }
        if (isset($anchors['SHDK'], $anchors['KEWARGANEGARAAN'])) {
            $splitShdkWni = ($anchors['SHDK'] + $anchors['KEWARGANEGARAAN']) / 2;
            $columns['SHDK'][1] = $splitShdkWni;
            $columns['KEWARGANEGARAAN'][0] = $splitShdkWni;
        }
        if (isset($anchors['AYAH'], $anchors['IBU'])) {
            $splitAyahIbu = ($anchors['AYAH'] + $anchors['IBU']) / 2;
            $columns['AYAH'][1] = $splitAyahIbu;
            $columns['IBU'][0] = $splitAyahIbu;
        }

        return $columns;
    }

    /**
     * Ekstraksi anggota dari Tabel 1 menggunakan NIK-First Anchor dan batas kolom.
     *
     * @return array<int, ParsedResident>
     */
    private function extractTableOneMembers(
        array $tokens,
        float $tTop,
        float $tBottom,
        array $columns,
        ?string $kkNumber,
        float $globalConfidence
    ): array {
        // 1. Temukan seluruh row anchor di wilayah Tabel 1
        $rowAnchors = [];
        $seenAnchorY = [];

        // 1a. NIK Anchors (Valid digits atau 16-char alphanumeric OCR correction)
        foreach ($tokens as $t) {
            if ($t['cy'] <= ($tTop + 15) || $t['cy'] >= $tBottom) {
                continue;
            }

            $raw = trim($t['text']);
            $norm = $this->normalizeNumericDigits($raw);
            $cleanDigits = preg_replace('/\D/', '', $norm);

            if (strlen($cleanDigits) === 16 && $cleanDigits !== $kkNumber) {
                if ($t['cx'] >= ($columns['NIK'][0] - 60) && $t['cx'] <= ($columns['NIK'][1] + 60)) {
                    $rowAnchors[] = [
                        'type' => 'NIK',
                        'nik' => $cleanDigits,
                        'cy' => (float) $t['cy'],
                        'cx' => (float) $t['cx'],
                        'height' => (float) $t['height'],
                        'token' => $t,
                    ];
                    $seenAnchorY[] = $t['cy'];
                }
            }
        }

        // 1b. Ordinal Row Numbers (1, 2, 3, 4, 5, ...) di kolom NO
        foreach ($tokens as $t) {
            if ($t['cy'] <= ($tTop + 15) || $t['cy'] >= $tBottom) {
                continue;
            }
            if ($t['cx'] <= $columns['NO'][1] && preg_match('/^[1-9]\b/', $t['text'], $om)) {
                $alreadyCovered = false;
                foreach ($seenAnchorY as $sy) {
                    if (abs($t['cy'] - $sy) <= 15.0) {
                        $alreadyCovered = true;
                        break;
                    }
                }
                if (! $alreadyCovered) {
                    $rowAnchors[] = [
                        'type' => 'ORDINAL',
                        'ordinal' => (int) $om[0],
                        'cy' => (float) $t['cy'],
                        'cx' => (float) $t['cx'],
                        'height' => (float) $t['height'],
                        'token' => $t,
                    ];
                    $seenAnchorY[] = $t['cy'];
                }
            }
        }

        // 1c. Name Clusters di kolom NAMA untuk baris yang belum tercover
        foreach ($tokens as $t) {
            if ($t['cy'] <= ($tTop + 15) || $t['cy'] >= $tBottom) {
                continue;
            }
            if ($t['cx'] >= $columns['NAMA'][0] && $t['cx'] <= $columns['NAMA'][1]) {
                $u = mb_strtoupper($t['text']);
                if ($this->isContaminatedNameToken($u) || strlen($t['text']) < 3) {
                    continue;
                }
                $alreadyCovered = false;
                foreach ($seenAnchorY as $sy) {
                    if (abs($t['cy'] - $sy) <= 16.0) {
                        $alreadyCovered = true;
                        break;
                    }
                }
                if (! $alreadyCovered) {
                    $rowAnchors[] = [
                        'type' => 'NAME',
                        'cy' => (float) $t['cy'],
                        'cx' => (float) $t['cx'],
                        'height' => (float) $t['height'],
                        'token' => $t,
                    ];
                    $seenAnchorY[] = $t['cy'];
                }
            }
        }

        // Urutkan anchors dari atas ke bawah
        usort($rowAnchors, fn ($a, $b) => $a['cy'] <=> $b['cy']);

        // Merge anchors yang terlalu berdekatan (<= 15px)
        $uniqueAnchors = [];
        foreach ($rowAnchors as $ra) {
            $merged = false;
            foreach ($uniqueAnchors as &$ua) {
                if (abs($ra['cy'] - $ua['cy']) <= 16.0) {
                    if (isset($ra['nik']) && ! isset($ua['nik'])) {
                        $ua['nik'] = $ra['nik'];
                    }
                    $ua['cy'] = ($ua['cy'] + $ra['cy']) / 2;
                    $merged = true;
                    break;
                }
            }
            unset($ua);
            if (! $merged) {
                $uniqueAnchors[] = $ra;
            }
        }

        if (empty($uniqueAnchors)) {
            return [];
        }

        // Tentukan toleransi tinggi baris
        $avgRowDist = 42.0;
        if (count($uniqueAnchors) > 1) {
            $dists = [];
            for ($i = 0; $i < count($uniqueAnchors) - 1; $i++) {
                $d = $uniqueAnchors[$i + 1]['cy'] - $uniqueAnchors[$i]['cy'];
                if ($d > 15 && $d < 150) {
                    $dists[] = $d;
                }
            }
            if (! empty($dists)) {
                $avgRowDist = array_sum($dists) / count($dists);
            }
        }

        $halfTolerance = max(12.0, min(25.0, $avgRowDist * 0.48));

        // 2. Kelompokkan token per baris anggota
        $members = [];
        $seenNiks = [];

        foreach ($uniqueAnchors as $idx => $anchor) {
            $targetY = $anchor['cy'];
            $rowNik = $anchor['nik'] ?? null;

            // Kumpulkan token yang berada pada rentang Y baris ini
            $rowTokens = [];
            foreach ($tokens as $t) {
                if ($t['cy'] <= ($tTop + 15) || $t['cy'] >= $tBottom) {
                    continue;
                }
                if (abs($t['cy'] - $targetY) <= $halfTolerance) {
                    $rowTokens[] = $t;
                }
            }

            // Urutkan token dalam baris dari kiri ke kanan
            usort($rowTokens, fn ($a, $b) => $a['cx'] <=> $b['cx']);

            // Distribusikan token ke kolom-kolom Tabel 1
            $colData = [
                'NO' => [],
                'NAMA' => [],
                'NIK' => [],
                'GENDER' => [],
                'TEMPAT_LAHIR' => [],
                'TANGGAL_LAHIR' => [],
                'AGAMA' => [],
                'PENDIDIKAN' => [],
                'PEKERJAAN' => [],
            ];

            foreach ($rowTokens as $rt) {
                $cx = $rt['cx'];
                $assigned = false;

                // Cek NO hanya jika angka 1-2 digit
                if ($cx < $columns['NO'][1] && preg_match('/^[0-9]{1,2}$/', trim($rt['text']))) {
                    $colData['NO'][] = $rt;
                    continue;
                }

                foreach ($columns as $colName => [$xMin, $xMax]) {
                    if ($colName === 'NO') {
                        continue;
                    }
                    if ($cx >= $xMin && $cx < $xMax) {
                        $colData[$colName][] = $rt;
                        $assigned = true;
                        break;
                    }
                }

                if (! $assigned) {
                    $bestCol = null;
                    $minDist = 9999.0;
                    foreach ($columns as $colName => [$xMin, $xMax]) {
                        if ($colName === 'NO') {
                            continue;
                        }
                        $mid = ($xMin + $xMax) / 2;
                        $dist = abs($cx - $mid);
                        if ($dist < $minDist) {
                            $minDist = $dist;
                            $bestCol = $colName;
                        }
                    }
                    if ($bestCol !== null) {
                        $colData[$bestCol][] = $rt;
                    }
                }
            }

            // 3. Rekonstruksi & Sanitasi Field Anggota
            $resident = $this->buildResidentFromTableOne($colData, $rowNik, $idx + 1, $globalConfidence);
            if ($resident !== null) {
                if ($resident->nik !== null) {
                    if (isset($seenNiks[$resident->nik])) {
                        continue;
                    }
                    $seenNiks[$resident->nik] = true;
                }
                $members[] = $resident;
            }
        }

        return $members;
    }

    /**
     * Membangun objek ParsedResident dari kolom Tabel 1 dengan Anti-Contamination ketat.
     */
    private function buildResidentFromTableOne(array $colData, ?string $anchorNik, int $ordinal, float $confidence): ?ParsedResident
    {
        // 1. NIK
        $nik = $anchorNik;
        if ($nik === null && ! empty($colData['NIK'])) {
            $nikRaw = implode('', array_column($colData['NIK'], 'text'));
            $norm = $this->normalizeNumericDigits($nikRaw);
            $cleanDigits = preg_replace('/\D/', '', $norm);
            if (strlen($cleanDigits) === 16) {
                $nik = $cleanDigits;
            }
        }

        // 2. NAMA LENGKAP (Strict Anti-Contamination)
        $namaTokens = [];
        foreach ($colData['NAMA'] as $nt) {
            $text = trim($nt['text']);
            $u = mb_strtoupper($text);

            // Cek apakah token nama tercemar oleh vocabulary kolom lain
            if ($this->isContaminatedNameToken($u)) {
                continue;
            }

            // Hapus noise index seperti "1.", "g)", "|", "#"
            $cleaned = preg_replace('/^[0-9]+[.)\]\-]+\s*/', '', $text);
            $cleaned = preg_replace('/[|*\/\[\]@#]/', '', $cleaned);
            $cleaned = trim($cleaned);

            if ($cleaned !== '' && ! preg_match('/^[0-9]+$/', $cleaned)) {
                $namaTokens[] = $cleaned;
            }
        }

        $nama = ! empty($namaTokens) ? implode(' ', $namaTokens) : null;
        if ($nama !== null) {
            $nama = $this->sanitizeCleanName($nama);
        }

        // 3. GENDER
        $gender = null;
        $genderRaw = implode(' ', array_column($colData['GENDER'], 'text'));
        $uGender = mb_strtoupper(preg_replace('/[_\s\-]+/u', '', $genderRaw));
        if (str_contains($uGender, 'PEREMPUAN') || str_contains($uGender, 'PEREMP') || $uGender === 'P' || $uGender === 'PR') {
            $gender = 'PEREMPUAN';
        } elseif (str_contains($uGender, 'LAKI') || $uGender === 'L' || $uGender === 'LK') {
            $gender = 'LAKI_LAKI';
        }

        // 4. TEMPAT LAHIR
        $tempatLahir = null;
        if (! empty($colData['TEMPAT_LAHIR'])) {
            $tRaw = implode(' ', array_column($colData['TEMPAT_LAHIR'], 'text'));
            $tClean = preg_replace('/[|*\/\[\]@#0-9]/', '', $tRaw);
            $tClean = trim($tClean);
            if ($tClean !== '' && strlen($tClean) >= 2) {
                $tempatLahir = mb_strtoupper($tClean);
            }
        }

        // 5. TANGGAL LAHIR
        $birthDate = null;
        $tglRaw = implode(' ', array_column($colData['TANGGAL_LAHIR'], 'text'));
        if (preg_match('/(\d{1,2})[\s.\-_\\/](\d{1,2})[\s.\-_\\/](\d{4})/', $tglRaw, $dm)) {
            $birthDate = sprintf('%02d-%02d-%04d', (int) $dm[1], (int) $dm[2], (int) $dm[3]);
        }

        // 6. AGAMA
        $religion = null;
        $relRaw = mb_strtoupper(implode(' ', array_column($colData['AGAMA'], 'text')));
        foreach (['ISLAM', 'KRISTEN', 'KATOLIK', 'HINDU', 'BUDDHA', 'KONGHUCU'] as $rel) {
            if (str_contains($relRaw, $rel)) {
                $religion = $rel;
                break;
            }
        }

        // 7. PENDIDIKAN
        $education = null;
        $eduRaw = mb_strtoupper(implode(' ', array_column($colData['PENDIDIKAN'], 'text')));
        $education = $this->normalizeEducationString($eduRaw);

        // 8. PEKERJAAN
        $occupation = null;
        $occRaw = mb_strtoupper(implode(' ', array_column($colData['PEKERJAAN'], 'text')));
        $occupation = $this->normalizeOccupationString($occRaw);

        // Abaikan jika baris benar-benar kosong tanpa nama, nik, maupun gender
        if ($nama === null && $nik === null && $gender === null) {
            return null;
        }

        return new ParsedResident(
            nama: $nama,
            nik: $nik,
            gender: $gender,
            birthPlace: $tempatLahir,
            birthDate: $birthDate,
            religion: $religion,
            education: $education,
            occupation: $occupation,
            maritalStatus: null,
            familyRelation: $ordinal === 1 ? 'KEPALA_KELUARGA' : null,
            confidence: $confidence,
            lowConfidence: $confidence < 70.0,
            ayah: null,
            ibu: null,
        );
    }

    /**
     * Ekstraksi baris Tabel 2 (Status Kawin, SHDK, Ayah, Ibu).
     *
     * @return array<int, array{maritalStatus: ?string, shdk: ?string, father: ?string, mother: ?string}>
     */
    private function extractTableTwoRows(array $tokens, float $tTop, float $tBottom, array $columns, int $expectedCount): array
    {
        // Kumpulkan token di wilayah Tabel 2
        $t2Tokens = [];
        foreach ($tokens as $t) {
            if ($t['cy'] > ($tTop + 15) && $t['cy'] < $tBottom) {
                $t2Tokens[] = $t;
            }
        }

        if (empty($t2Tokens)) {
            return [];
        }

        // Cluster berdasarkan Y
        usort($t2Tokens, fn ($a, $b) => $a['cy'] <=> $b['cy']);

        // Temukan baris-baris data di Tabel 2
        $rowBands = [];
        foreach ($t2Tokens as $t) {
            $placed = false;
            foreach ($rowBands as &$band) {
                if (abs($t['cy'] - $band['cy']) <= 18.0) {
                    $band['tokens'][] = $t;
                    $band['cy'] = array_sum(array_column($band['tokens'], 'cy')) / count($band['tokens']);
                    $placed = true;
                    break;
                }
            }
            unset($band);

            if (! $placed) {
                $rowBands[] = [
                    'cy' => $t['cy'],
                    'tokens' => [$t],
                ];
            }
        }

        // Filter baris yang memiliki minimal 1 token
        $rowBands = array_filter($rowBands, fn ($b) => count($b['tokens']) >= 1);
        usort($rowBands, fn ($a, $b) => $a['cy'] <=> $b['cy']);
        $rowBands = array_values($rowBands);

        $results = [];
        foreach ($rowBands as $idx => $band) {
            $tokens = $band['tokens'];
            usort($tokens, fn ($a, $b) => $a['cx'] <=> $b['cx']);

            $colData = [
                'NO' => [],
                'STATUS_KAWIN' => [],
                'TANGGAL_KAWIN' => [],
                'SHDK' => [],
                'KEWARGANEGARAAN' => [],
                'PASPOR_KITAS' => [],
                'AYAH' => [],
                'IBU' => [],
            ];

            foreach ($tokens as $t) {
                $cx = $t['cx'];
                foreach ($columns as $colName => [$xMin, $xMax]) {
                    if ($cx >= $xMin && $cx < $xMax) {
                        $colData[$colName][] = $t;
                        break;
                    }
                }
            }

            // Parse Status Kawin
            $statusKawin = null;
            $skRaw = mb_strtoupper(implode(' ', array_column($colData['STATUS_KAWIN'], 'text')));
            if (str_contains($skRaw, 'BELUM') || (str_contains($skRaw, 'BELUM') && str_contains($skRaw, 'KAWIN')) || str_contains($skRaw, 'KARIN')) {
                $statusKawin = 'BELUM_KAWIN';
            } elseif (str_contains($skRaw, 'KAWIN') || str_contains($skRaw, 'KAKIN')) {
                $statusKawin = 'KAWIN';
            } elseif (str_contains($skRaw, 'CERAI') && str_contains($skRaw, 'HIDUP')) {
                $statusKawin = 'CERAI_HIDUP';
            } elseif (str_contains($skRaw, 'CERAI') && str_contains($skRaw, 'MATI')) {
                $statusKawin = 'CERAI_MATI';
            }

            // Parse SHDK
            $shdk = null;
            $shdkRaw = mb_strtoupper(implode(' ', array_column($colData['SHDK'], 'text')));
            if (str_contains($shdkRaw, 'KEPALA') || str_contains($shdkRaw, 'KELUARGA')) {
                $shdk = 'KEPALA_KELUARGA';
            } elseif (str_contains($shdkRaw, 'ISTRI')) {
                $shdk = 'ISTRI';
            } elseif (str_contains($shdkRaw, 'ANAK')) {
                $shdk = 'ANAK';
            } elseif (str_contains($shdkRaw, 'MENANTU')) {
                $shdk = 'MENANTU';
            } elseif (str_contains($shdkRaw, 'CUCU')) {
                $shdk = 'CUCU';
            } elseif (str_contains($shdkRaw, 'ORANG TUA') || str_contains($shdkRaw, 'MERTUA')) {
                $shdk = 'ORANG_TUA';
            } elseif (str_contains($shdkRaw, 'FAMILI')) {
                $shdk = 'FAMILI_LAIN';
            }

            // Parse Ayah & Ibu
            $father = null;
            if (! empty($colData['AYAH'])) {
                $fRaw = implode(' ', array_column($colData['AYAH'], 'text'));
                $fClean = $this->sanitizeCleanName($fRaw);
                if ($fClean !== '' && strlen($fClean) >= 2) {
                    $father = $fClean;
                }
            }

            $mother = null;
            if (! empty($colData['IBU'])) {
                $mRaw = implode(' ', array_column($colData['IBU'], 'text'));
                $mClean = $this->sanitizeCleanName($mRaw);
                if ($mClean !== '' && strlen($mClean) >= 2) {
                    $mother = $mClean;
                }
            }

            $results[] = [
                'maritalStatus' => $statusKawin,
                'shdk' => $shdk,
                'father' => $father,
                'mother' => $mother,
            ];
        }

        return $results;
    }

    /**
     * Gabungkan data Tabel 1 dan Tabel 2.
     *
     * @param  array<int, ParsedResident>  $t1Members
     * @param  array<int, array{maritalStatus: ?string, shdk: ?string, father: ?string, mother: ?string}>  $t2Rows
     * @return array<int, ParsedResident>
     */
    private function mergeTableOneAndTwo(array $t1Members, array $t2Rows): array
    {
        $merged = [];
        $t2Count = count($t2Rows);

        foreach ($t1Members as $idx => $m) {
            $t2 = $idx < $t2Count ? $t2Rows[$idx] : null;

            $shdk = $t2['shdk'] ?? $m->familyRelation;
            if ($idx === 0 && ($shdk === null || $shdk === '')) {
                $shdk = 'KEPALA_KELUARGA';
            }

            $merged[] = new ParsedResident(
                nama: $m->nama,
                nik: $m->nik,
                gender: $m->gender,
                birthPlace: $m->birthPlace,
                birthDate: $m->birthDate,
                religion: $m->religion,
                education: $m->education,
                occupation: $m->occupation,
                maritalStatus: $t2['maritalStatus'] ?? $m->maritalStatus,
                familyRelation: $shdk,
                confidence: $m->confidence,
                lowConfidence: $m->lowConfidence,
                ayah: $t2['father'] ?? $m->ayah,
                ibu: $t2['mother'] ?? $m->ibu,
            );
        }

        return $merged;
    }

    /**
     * Periksa apakah sebuah token mengandung kata kunci kontaminasi.
     */
    private function isContaminatedNameToken(string $upperToken): bool
    {
        $clean = preg_replace('/[^A-Z]/', '', $upperToken);
        if ($clean === '') {
            return false;
        }

        foreach (self::NAME_CONTAMINATION_WORDS as $badWord) {
            $cleanBad = preg_replace('/[^A-Z]/', '', $badWord);
            if ($clean === $cleanBad) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitasi nama dari simbol noise dan karakter non-alfabet.
     */
    private function sanitizeCleanName(string $name): string
    {
        $name = preg_replace('/[|*\/\[\]@#^~`{}$%+=<>?!:;]/', '', $name);
        $name = preg_replace('/\b[0-9]+\b/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        return mb_strtoupper(trim($name, " \t\n\r\0\x0B-_.,"));
    }

    /**
     * Normalisasi digit numerik dari karakter OCR.
     */
    private function normalizeNumericDigits(string $value): string
    {
        return strtr(
            $value,
            [
                'O' => '0',
                'o' => '0',
                'D' => '0',
                'I' => '1',
                'l' => '1',
                '|' => '1',
                '!' => '1',
                'S' => '5',
                's' => '5',
                'B' => '8',
                'b' => '6',
                'G' => '6',
                'Z' => '2',
                'z' => '2',
            ]
        );
    }

    /**
     * Normalisasi string pendidikan ke canonical SIPETA.
     */
    private function normalizeEducationString(string $raw): ?string
    {
        $u = mb_strtoupper($raw);
        if (str_contains($u, 'BELUM') && str_contains($u, 'SEKOLAH')) {
            return 'TIDAK/BELUM SEKOLAH';
        }
        if (str_contains($u, 'BELUM') && str_contains($u, 'TAMAT')) {
            return 'BELUM TAMAT SD/SEDERAJAT';
        }
        if (str_contains($u, 'SLTA') || str_contains($u, 'SMA') || str_contains($u, 'SMK') || str_contains($u, 'SLTA/SEDERAJAT')) {
            return 'SLTA/SEDERAJAT';
        }
        if (str_contains($u, 'SLTP') || str_contains($u, 'SMP') || str_contains($u, 'SLTP/SEDERAJAT')) {
            return 'SLTP/SEDERAJAT';
        }
        if (str_contains($u, 'TAMAT') || str_contains($u, 'SD/SEDERAJAT') || (str_contains($u, 'SD') && ! str_contains($u, 'BELUM'))) {
            return 'TAMAT SD/SEDERAJAT';
        }
        if (str_contains($u, 'STRATA I') || str_contains($u, 'STRATA-I') || str_contains($u, 'S1') || str_contains($u, 'DIPLOMA IV') || str_contains($u, 'D4') || str_contains($u, 'SARJANA') || str_contains($u, 'STRATA')) {
            return 'DIPLOMA IV/STRATA I';
        }
        if (str_contains($u, 'DIPLOMA III') || str_contains($u, 'D3') || str_contains($u, 'AKADEMI')) {
            return 'AKADEMI/DIPLOMA III/SARJANA MUDA';
        }
        if (str_contains($u, 'DIPLOMA I') || str_contains($u, 'D1') || str_contains($u, 'D2')) {
            return 'DIPLOMA I/II';
        }
        if (str_contains($u, 'STRATA II') || str_contains($u, 'S2') || str_contains($u, 'MAGISTER')) {
            return 'STRATA II';
        }
        if (str_contains($u, 'STRATA III') || str_contains($u, 'S3') || str_contains($u, 'DOKTOR')) {
            return 'STRATA III';
        }

        return null;
    }

    /**
     * Normalisasi string pekerjaan ke canonical SIPETA.
     */
    private function normalizeOccupationString(string $raw): ?string
    {
        $u = mb_strtoupper($raw);
        if (str_contains($u, 'BELUM') || str_contains($u, 'TIDAK BEKERJA')) {
            return 'BELUM/TIDAK BEKERJA';
        }
        if (str_contains($u, 'PELAJAR') || str_contains($u, 'MAHASISWA') || str_contains($u, 'PELAJAR/MAHASISHA')) {
            return 'PELAJAR/MAHASISWA';
        }
        if (str_contains($u, 'IBU RUMAH TANGGA') || str_contains($u, 'MENGURUS RUMAH TANGGA') || str_contains($u, 'RUMAH TANGGA')) {
            return 'MENGURUS RUMAH TANGGA';
        }
        if (str_contains($u, 'PETANI') || str_contains($u, 'PEKEBUN')) {
            return 'PETANI/PEKEBUN';
        }
        if (str_contains($u, 'KARYAWAN SWASTA') || str_contains($u, 'SWASTA') || str_contains($u, 'KARYAKAN') || str_contains($u, 'SHASTA')) {
            return 'KARYAWAN SWASTA';
        }
        if (str_contains($u, 'PNS') || str_contains($u, 'PEGAWAI NEGERI')) {
            return 'PEGAWAI NEGERI SIPIL';
        }
        if (str_contains($u, 'WIRASWASTA') || str_contains($u, 'PEDAGANG') || str_contains($u, 'WIRAUSAHA')) {
            return 'WIRASWASTA';
        }
        if (str_contains($u, 'BURUH')) {
            return 'BURUH HARIAN LEPAS';
        }

        return null;
    }
}
