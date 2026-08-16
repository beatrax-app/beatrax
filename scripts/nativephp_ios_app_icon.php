<?php

declare(strict_types=1);

/*
 * Writes the iOS app icon from resources/brand art.
 *
 * NativePHP ships its own "N" mark in the AppIcon asset set, and nothing
 * replaced it — Android had nativephp_android_adaptive_icon.php, iOS had no
 * counterpart, so the phone showed the framework's logo on the home screen
 * while Android showed ours.
 *
 * iOS masks the icon itself and rejects an alpha channel outright, so this is
 * one flattened 1024 square: the mark composited over the same white the
 * Android background layer uses, at the same share of the canvas, so the two
 * platforms read as the same icon rather than merely the same artwork.
 */

$root = dirname(__DIR__);

$source = $root.'/public/icon.png';

if (! is_file($source)) {
    fwrite(STDERR, "ios-app-icon: source {$source} not found\n");

    exit(0);
}

$iconset = $root.'/mobile-app/nativephp/ios/NativePHP/Assets.xcassets/AppIcon.appiconset';

if (! is_dir($iconset)) {
    fwrite(STDERR, "ios-app-icon: iconset not found (run native:install first)\n");

    exit(0);
}

// The App Store's only required size; Xcode derives every other slot from it.
const ICON_SIZE = 1024;

// Higher than the Android script's 0.64 on purpose: that number buys clearance
// from a round launcher mask, and iOS's superellipse barely crops the corners.
// Held to the same ratio the mark looked marooned in a field of white.
const LOGO_RATIO = 0.80;

/**
 * The bounding box of actual logo ink — neither transparent nor near-white —
 * so the card's padding never becomes part of the mark being scaled. Same
 * rule as the Android script; scaling the padded source instead shrinks the
 * mark by however much whitespace the artwork happens to carry.
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

$src = imagecreatefrompng($source);

if ($src === false) {
    fwrite(STDERR, "ios-app-icon: could not read {$source}\n");

    exit(1);
}

$canvas = imagecreatetruecolor(ICON_SIZE, ICON_SIZE);

// No alpha saved: an icon with a transparency channel is rejected at submission
// and renders black on device.
imagealphablending($canvas, true);
imagesavealpha($canvas, false);

$white = imagecolorallocate($canvas, 255, 255, 255);
imagefilledrectangle($canvas, 0, 0, ICON_SIZE, ICON_SIZE, $white);

[$left, $top, $right, $bottom] = inkBounds($src);

$inkWidth = max(1, $right - $left + 1);
$inkHeight = max(1, $bottom - $top + 1);

// Scaled by its longer side so a non-square mark keeps its proportions and
// still fits the box.
$box = (int) round(ICON_SIZE * LOGO_RATIO);
$scale = $box / max($inkWidth, $inkHeight);
$drawWidth = (int) round($inkWidth * $scale);
$drawHeight = (int) round($inkHeight * $scale);

imagecopyresampled(
    $canvas,
    $src,
    (int) round((ICON_SIZE - $drawWidth) / 2),
    (int) round((ICON_SIZE - $drawHeight) / 2),
    $left,
    $top,
    $drawWidth,
    $drawHeight,
    $inkWidth,
    $inkHeight,
);

$written = imagepng($canvas, $iconset.'/icon.png');

if (! $written) {
    fwrite(STDERR, "ios-app-icon: could not write the iconset\n");

    exit(1);
}

echo 'ios-app-icon: wrote '.ICON_SIZE."px app icon\n";
