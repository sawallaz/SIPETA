<?php

$width = 2480;
$height = 1754;

$image = imagecreatetruecolor($width, $height);
$white = imagecolorallocate($image, 255, 255, 255);
$black = imagecolorallocate($image, 15, 23, 42);
$gray = imagecolorallocate($image, 148, 163, 184);
$lightGray = imagecolorallocate($image, 241, 245, 249);
$tableHeaderBg = imagecolorallocate($image, 226, 232, 240);

imagefill($image, 0, 0, $white);

$fontRegular = 'C:\Windows\Fonts\arial.ttf';
$fontBold = 'C:\Windows\Fonts\arialbd.ttf';

if (!file_exists($fontRegular)) {
    $fontRegular = $fontBold = 5; // GD built-in font fallback
}

// Function to draw text
function drawText($img, $size, $angle, $x, $y, $color, $font, $text) {
    if (is_string($font) && file_exists($font)) {
        imagettftext($img, $size, $angle, $x, $y, $color, $font, $text);
    } else {
        imagestring($img, 5, $x, $y - 12, $text, $color);
    }
}

// Function to draw centered text
function drawCenteredText($img, $size, $y, $color, $font, $text, $width) {
    if (is_string($font) && file_exists($font)) {
        $box = imagettfbbox($size, 0, $font, $text);
        $textWidth = abs($box[4] - $box[0]);
        $x = (int)(($width - $textWidth) / 2);
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    } else {
        $x = (int)(($width - (strlen($text) * 9)) / 2);
        imagestring($img, 5, $x, $y - 12, $text, $color);
    }
}

// Header
drawCenteredText($image, 20, 70, $black, $fontBold, 'REPUBLIK INDONESIA', $width);
drawCenteredText($image, 32, 120, $black, $fontBold, 'KARTU KELUARGA', $width);
drawCenteredText($image, 24, 165, $black, $fontBold, 'No. 7304012304990001', $width);

// Decorative double line under title
imagesetthickness($image, 3);
imageline($image, 80, 190, $width - 80, 190, $black);
imagesetthickness($image, 1);
imageline($image, 80, 195, $width - 80, 195, $black);

// Header Metadata Details (2 columns)
$leftX = 100;
$rightX = 1350;
$metaY = 240;
$lineHeight = 35;

$metaLeft = [
    ['Nama Kepala Keluarga', 'MUHAMMAD AMIN'],
    ['Alamat', 'JL. POROS PARE-PARE NO. 45'],
    ['RT/RW', '001/002'],
    ['Desa/Kelurahan', 'TANETE'],
];

$metaRight = [
    ['Kecamatan', 'TANETE RILAU'],
    ['Kabupaten/Kota', 'BARRU'],
    ['Kode Pos', '90761'],
    ['Provinsi', 'SULAWESI SELATAN'],
];

foreach ($metaLeft as $idx => $item) {
    $y = $metaY + ($idx * $lineHeight);
    drawText($image, 14, 0, $leftX, $y, $black, $fontBold, $item[0]);
    drawText($image, 14, 0, $leftX + 240, $y, $black, $fontBold, ':');
    drawText($image, 14, 0, $leftX + 260, $y, $black, $fontRegular, $item[1]);
}

foreach ($metaRight as $idx => $item) {
    $y = $metaY + ($idx * $lineHeight);
    drawText($image, 14, 0, $rightX, $y, $black, $fontBold, $item[0]);
    drawText($image, 14, 0, $rightX + 200, $y, $black, $fontBold, ':');
    drawText($image, 14, 0, $rightX + 220, $y, $black, $fontRegular, $item[1]);
}

// ----------------------------------------------------
// TABLE 1: Data Pribadi
// ----------------------------------------------------
$t1Y = 410;
$t1ColWidths = [
    60,   // No
    420,  // Nama Lengkap
    360,  // NIK
    210,  // Jenis Kelamin
    220,  // Tempat Lahir
    210,  // Tanggal Lahir
    160,  // Agama
    310,  // Pendidikan
    320   // Jenis Pekerjaan
]; // Total = 2270 (fits inside 2480 with margin 105 each side)
$startX = 105;

$t1Headers = [
    'No',
    'Nama Lengkap',
    'NIK',
    'Jenis Kelamin',
    'Tempat Lahir',
    'Tanggal Lahir',
    'Agama',
    'Pendidikan',
    'Jenis Pekerjaan'
];

$t1Rows = [
    ['1', 'MUHAMMAD AMIN', '7304010101750001', 'LAKI-LAKI', 'BARRU', '01-01-1975', 'ISLAM', 'SLTA/SEDERAJAT', 'PETANI'],
    ['2', 'NURHAYATI', '7304014506800002', 'PEREMPUAN', 'BARRU', '05-06-1980', 'ISLAM', 'SLTA/SEDERAJAT', 'IBU RUMAH TANGGA'],
    ['3', 'AHMAD FAUZI', '7304011208050003', 'LAKI-LAKI', 'BARRU', '12-08-2005', 'ISLAM', 'SLTA/SEDERAJAT', 'PELAJAR/MAHASISWA'],
    ['4', 'SITI AISYAH', '7304015011100004', 'PEREMPUAN', 'BARRU', '10-11-2010', 'ISLAM', 'SLTP/SEDERAJAT', 'PELAJAR/MAHASISWA'],
];

function drawTable($img, $startX, $startY, $colWidths, $headers, $rows, $fontBold, $fontRegular, $black, $tableHeaderBg) {
    $headerHeight = 50;
    $rowHeight = 45;
    $totalWidth = array_sum($colWidths);
    $totalHeight = $headerHeight + (count($rows) * $rowHeight);

    // Header Background
    imagefilledrectangle($img, $startX, $startY, $startX + $totalWidth, $startY + $headerHeight, $tableHeaderBg);

    // Outer border
    imagesetthickness($img, 2);
    imagerectangle($img, $startX, $startY, $startX + $totalWidth, $startY + $totalHeight, $black);

    // Horizontal header line
    imageline($img, $startX, $startY + $headerHeight, $startX + $totalWidth, $startY + $headerHeight, $black);

    // Column headers text & Vertical lines
    $currX = $startX;
    imagesetthickness($img, 1);
    foreach ($colWidths as $cIdx => $w) {
        $text = $headers[$cIdx];
        drawText($img, 11, 0, $currX + 8, $startY + 32, $black, $fontBold, $text);
        if ($cIdx > 0) {
            imageline($img, $currX, $startY, $currX, $startY + $totalHeight, $black);
        }
        $currX += $w;
    }

    // Rows
    $currY = $startY + $headerHeight;
    foreach ($rows as $rIdx => $row) {
        $currX = $startX;
        foreach ($colWidths as $cIdx => $w) {
            $val = $row[$cIdx] ?? '';
            drawText($img, 12, 0, $currX + 8, $currY + 28, $black, $fontRegular, $val);
            $currX += $w;
        }
        $currY += $rowHeight;
        imageline($img, $startX, $currY, $startX + $totalWidth, $currY, $black);
    }

    return $startY + $totalHeight;
}

$endT1Y = drawTable($image, $startX, $t1Y, $t1ColWidths, $t1Headers, $t1Rows, $fontBold, $fontRegular, $black, $tableHeaderBg);

// ----------------------------------------------------
// TABLE 2: Status Perkawinan, Hubungan, Orang Tua
// ----------------------------------------------------
$t2Y = $endT1Y + 50;

$t2ColWidths = [
    60,   // No
    240,  // Status Perkawinan
    240,  // Tanggal Perkawinan
    400,  // Status Hubungan Dalam Keluarga
    220,  // Kewarganegaraan
    180,  // No. Paspor
    180,  // No. KITAS
    375,  // Nama Ayah
    375   // Nama Ibu
]; // Total = 2270

$t2Headers = [
    'No',
    'Status Perkawinan',
    'Tanggal Perkawinan',
    'Status Hubungan Dalam Keluarga',
    'Kewarganegaraan',
    'No. Paspor',
    'No. KITAS',
    'Nama Ayah',
    'Nama Ibu'
];

$t2Rows = [
    ['1', 'KAWIN', '10-05-2000', 'KEPALA KELUARGA', 'WNI', '-', '-', 'ABDULLAH', 'FATIMAH'],
    ['2', 'KAWIN', '10-05-2000', 'ISTRI', 'WNI', '-', '-', 'HASAN', 'MARIAM'],
    ['3', 'BELUM KAWIN', '-', 'ANAK', 'WNI', '-', '-', 'MUHAMMAD AMIN', 'NURHAYATI'],
    ['4', 'BELUM KAWIN', '-', 'ANAK', 'WNI', '-', '-', 'MUHAMMAD AMIN', 'NURHAYATI'],
];

$endT2Y = drawTable($image, $startX, $t2Y, $t2ColWidths, $t2Headers, $t2Rows, $fontBold, $fontRegular, $black, $tableHeaderBg);

// Footer Signatures
$sigY = $endT2Y + 80;
drawText($image, 14, 0, 150, $sigY, $black, $fontBold, 'KEPALA KELUARGA');
drawText($image, 14, 0, 150, $sigY + 140, $black, $fontBold, 'MUHAMMAD AMIN');

drawText($image, 14, 0, 1750, $sigY - 30, $black, $fontRegular, 'Dikeluarkan di: BARRU');
drawText($image, 14, 0, 1750, $sigY, $black, $fontRegular, 'Pada Tanggal: 15-08-2022');
drawText($image, 14, 0, 1750, $sigY + 30, $black, $fontBold, 'KEPALA DINAS KEPENDUDUKAN');
drawText($image, 14, 0, 1750, $sigY + 60, $black, $fontBold, 'DAN PENCATATAN SIPIL');
drawText($image, 14, 0, 1750, $sigY + 160, $black, $fontBold, 'DRS. H. SYAMSUDDIN, M.SI');

$outFile = __DIR__ . '/sample_kk.png';
imagepng($image, $outFile, 0); // Lossless PNG
imagedestroy($image);

echo "Sample KK generated successfully at: " . realpath($outFile) . "\n";
echo "Dimensions: {$width}x{$height} px\n";
