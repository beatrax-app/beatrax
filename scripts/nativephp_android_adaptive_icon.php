<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Regenerates the Android adaptive launcher icon from resources/brand art.
 *
 * NativePHP ships an iOS-shaped square and a stub background layer that still
 * carries its "change this to match your desired BG" comment. Android then
 * composites a 55%-sized foreground over plain white and masks the result, so
 * the launcher showed the logo adrift in white with visible gaps at the
 * corners and bottom.
 *
 * An adaptive icon is two layers on a 108dp canvas: only the inner ~66dp is
 * guaranteed visible, the rest is what the launcher's mask eats. So the
 * foreground here is the logo ink alone — cropped out of its card, scaled to
 * fill that safe zone, and centred on transparency — over a background layer
 * that owns the colour.
 */

$root = dirname(__DIR__);
$source = $root.'/public/icon.png';
$res = beatraxScaffoldPath('android/app/src/main/res') ?? '';

if (! is_file($source)) {
    fwrite(STDERR, "adaptive-icon: source {$source} not found\n");

    exit(0);
}

if (! is_dir($res)) {
    fwrite(STDERR, "adaptive-icon: android res dir not found (run native:install first)\n");

    exit(0);
}

// The colour the launcher fills behind the logo. Taken from the artwork's own
// card so the two layers read as one shape under any mask.
$backgroundHex = '#ffffff';

// Share of the canvas the logo occupies. Android guarantees the inner 66%;
// sitting just inside it keeps the mark clear of every mask shape without
// looking marooned the way 55% did.
const SAFE_ZONE_RATIO = 0.64;

/** Foreground canvas size per density bucket, in px (108dp). */
const FOREGROUND_SIZES = [
    'mipmap-mdpi' => 108,
    'mipmap-hdpi' => 162,
    'mipmap-xhdpi' => 216,
    'mipmap-xxhdpi' => 324,
    'mipmap-xxxhdpi' => 432,
];

/** Legacy square icon size per density bucket, in px (48dp). */
const LEGACY_SIZES = [
    'mipmap-mdpi' => 48,
    'mipmap-hdpi' => 72,
    'mipmap-xhdpi' => 96,
    'mipmap-xxhdpi' => 144,
    'mipmap-xxxhdpi' => 192,
];

/**
 * The bounding box of actual logo ink — neither transparent nor near-white —
 * so the card's padding never becomes part of the mark being scaled.
 *
 * @return array{0: int, 1: int, 2: int, 3: int}
 */
function inkBounds(GdImage $image): array
{
    $w = imagesx($image);
    $h = imagesy($image);
    $left = $w;
    $top = $h;
    $right = 0;
    $bottom = 0;

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($image, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;

            if ($alpha > 100 || ($r > 235 && $g > 235 && $b > 235)) {
                continue;
            }

            $left = min($left, $x);
            $top = min($top, $y);
            $right = max($right, $x);
            $bottom = max($bottom, $y);
        }
    }

    return [$left, $top, $right, $bottom];
}

function transparentCanvas(int $size): GdImage
{
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefilledrectangle($canvas, 0, 0, $size, $size, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    imagealphablending($canvas, true);

    return $canvas;
}

$src = imagecreatefrompng($source);
imagealphablending($src, false);
imagesavealpha($src, true);

[$left, $top, $right, $bottom] = inkBounds($src);
$inkW = $right - $left + 1;
$inkH = $bottom - $top + 1;

foreach (FOREGROUND_SIZES as $bucket => $canvasSize) {
    $dir = $res.'/'.$bucket;
    if (! is_dir($dir)) {
        continue;
    }

    // Scale the LONGEST ink edge into the safe zone so the mark keeps its
    // proportions, then centre both axes — the old art sat high, which is
    // what read as extra space along the bottom.
    $target = (int) round($canvasSize * SAFE_ZONE_RATIO);
    $scale = $target / max($inkW, $inkH);
    $drawW = (int) round($inkW * $scale);
    $drawH = (int) round($inkH * $scale);

    $canvas = transparentCanvas($canvasSize);
    imagecopyresampled(
        $canvas,
        $src,
        (int) round(($canvasSize - $drawW) / 2),
        (int) round(($canvasSize - $drawH) / 2),
        $left,
        $top,
        $drawW,
        $drawH,
        $inkW,
        $inkH,
    );

    imagepng($canvas, $dir.'/ic_launcher_foreground.png');

    // The pre-adaptive fallback is a plain square: same mark, same centring,
    // on the background colour rather than transparency.
    $legacySize = LEGACY_SIZES[$bucket];
    $legacyTarget = (int) round($legacySize * 0.72);
    $legacyScale = $legacyTarget / max($inkW, $inkH);
    $legacyW = (int) round($inkW * $legacyScale);
    $legacyH = (int) round($inkH * $legacyScale);

    $legacy = imagecreatetruecolor($legacySize, $legacySize);
    imagesavealpha($legacy, true);
    imagefilledrectangle($legacy, 0, 0, $legacySize, $legacySize, imagecolorallocate($legacy, 255, 255, 255));
    imagecopyresampled(
        $legacy,
        $src,
        (int) round(($legacySize - $legacyW) / 2),
        (int) round(($legacySize - $legacyH) / 2),
        $left,
        $top,
        $legacyW,
        $legacyH,
        $inkW,
        $inkH,
    );

    imagepng($legacy, $dir.'/ic_launcher.png');
    imagepng($legacy, $dir.'/ic_launcher_round.png');
}

// The background layer owns the colour; leaving NativePHP's stub in place is
// what put a "change this" placeholder into a shipped icon.
$background = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<!-- Generated by scripts/nativephp_android_adaptive_icon.php -->
<shape xmlns:android="http://schemas.android.com/apk/res/android"
       android:shape="rectangle">
    <solid android:color="{$backgroundHex}"/>
</shape>

XML;

file_put_contents($res.'/drawable/ic_launcher_background.xml', $background);

echo "adaptive-icon: regenerated foreground + legacy icons from public/icon.png\n";
