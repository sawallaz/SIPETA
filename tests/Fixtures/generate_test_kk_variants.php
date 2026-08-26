<?php

/**
 * generate_test_kk_variants.php
 *
 * Standalone GD script — no Laravel bootstrap required.
 *
 * Generates three KK image variants from the existing sample_kk.png:
 *   1. kk_clean_highres.png  — identical copy (re-saved as PNG)
 *   2. kk_low_res.png        — proportionally scaled to ~1100 px wide
 *   3. kk_camera_noise.png   — green tint + pixel noise (stride 3) + 1.5° rotation
 */

$src = __DIR__ . '/sample_kk.png';
$out = __DIR__ . '/';

if (! file_exists($src)) {
    echo "ERROR: source file not found: {$src}\n";
    exit(1);
}

// ─── Load source ─────────────────────────────────────────────────────────────

echo "Loading source image: {$src}\n";

$source = imagecreatefrompng($src);

if ($source === false) {
    echo "ERROR: imagecreatefrompng() failed.\n";
    exit(1);
}

$srcW = imagesx($source);
$srcH = imagesy($source);
echo "  Source dimensions: {$srcW} x {$srcH}\n\n";

// ─── 1. kk_clean_highres.png ─────────────────────────────────────────────────

echo "1. Generating kk_clean_highres.png (clean copy)...\n";

$cleanPath = $out . 'kk_clean_highres.png';

$clean = imagecreatetruecolor($srcW, $srcH);
imagecopy($clean, $source, 0, 0, 0, 0, $srcW, $srcH);
imagepng($clean, $cleanPath);
imagedestroy($clean);

echo "   Saved: {$cleanPath} (" . number_format(filesize($cleanPath)) . " bytes)\n\n";

// ─── 2. kk_low_res.png ───────────────────────────────────────────────────────

echo "2. Generating kk_low_res.png (~1100px wide, proportional)...\n";

$targetW = 1100;
$scale   = $targetW / $srcW;
$newW    = (int) round($srcW * $scale);
$newH    = (int) round($srcH * $scale);

$lowRes = imagecreatetruecolor($newW, $newH);
imagecopyresampled($lowRes, $source, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

$lowPath = $out . 'kk_low_res.png';
imagepng($lowRes, $lowPath);
imagedestroy($lowRes);

echo "   Dimensions: {$newW} x {$newH}\n";
echo "   Saved: {$lowPath} (" . number_format(filesize($lowPath)) . " bytes)\n\n";

// ─── 3. kk_camera_noise.png ──────────────────────────────────────────────────

echo "3. Generating kk_camera_noise.png (green tint + noise + 1.5° rotation)...\n";

// Start with a fresh copy of the source
$noisy = imagecreatetruecolor($srcW, $srcH);
imagecopy($noisy, $source, 0, 0, 0, 0, $srcW, $srcH);

// 3a. Green tint + random pixel noise (stride = 3 for speed)
echo "   Applying green tint and pixel noise (stride=3)...\n";

$stride = 3;
for ($y = 0; $y < $srcH; $y += $stride) {
    for ($x = 0; $x < $srcW; $x += $stride) {
        $rgba = imagecolorat($noisy, $x, $y);

        $r = ($rgba >> 16) & 0xFF;
        $g = ($rgba >> 8)  & 0xFF;
        $b =  $rgba        & 0xFF;

        // Green channel boost (+20)
        $g = min(255, $g + 20);

        // Random noise ±15
        $noise = mt_rand(-15, 15);
        $r = max(0, min(255, $r + $noise));
        $g = max(0, min(255, $g + $noise));
        $b = max(0, min(255, $b + $noise));

        $color = imagecolorallocate($noisy, $r, $g, $b);
        if ($color !== false) {
            imagesetpixel($noisy, $x, $y, $color);
        }
    }
}

// 3b. Rotate 1.5 degrees with white background
echo "   Rotating 1.5 degrees...\n";

$white = imagecolorallocate($noisy, 255, 255, 255);
$rotated = imagerotate($noisy, 1.5, $white);
imagedestroy($noisy);

if ($rotated === false) {
    echo "ERROR: imagerotate() failed.\n";
    exit(1);
}

$noisyPath = $out . 'kk_camera_noise.png';
imagepng($rotated, $noisyPath);
imagedestroy($rotated);

echo "   Saved: {$noisyPath} (" . number_format(filesize($noisyPath)) . " bytes)\n\n";

// ─── Cleanup ─────────────────────────────────────────────────────────────────

imagedestroy($source);

echo "Done. All 3 variants generated successfully.\n";
