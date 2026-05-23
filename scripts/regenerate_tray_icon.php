#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-shot generator for the macOS menu-bar tray icon assets.
 *
 * The macOS tray icon must be a template image: black-on-transparent at the
 * menu-bar logical size (~22 logical points) with a 2x sibling at 44 px so
 * macOS auto-tints it for light/dark menu-bar appearance. The committed app
 * icon at `public/icon.png` is a 512x512 full-color illustration — using it
 * directly as a tray icon (even with `setTemplateImage(true)` plumbed
 * through) renders a solid white block because the entire alpha channel is
 * opaque, so macOS treats the whole frame as silhouette.
 *
 * This script derives a monochrome silhouette from `public/icon.png` by:
 *   1. Loading the source RGBA image via GD.
 *   2. For every pixel: if alpha is below an opacity threshold OR the pixel
 *      is close to pure white (the illustration's background), set the
 *      output pixel to fully transparent. Otherwise paint it solid black
 *      with full opacity.
 *   3. Saving the resulting 1-bit-style silhouette at 22x22 and 44x44 to
 *      `resources/brand/tray-icon.png` and `resources/brand/tray-icon@2x.png`.
 *
 * Run this once (or whenever the brand changes); the produced files are
 * committed to the repo and staged into the build at every native build via
 * the existing `nativephp_stage_build_resources.php` / the new tray-icon
 * staging path.
 *
 * Usage:
 *   php scripts/regenerate_tray_icon.php
 */
$projectRoot = dirname(__DIR__);
$sourcePath = $projectRoot.'/public/icon.png';
$outputBase = $projectRoot.'/resources/brand/tray-icon.png';
$outputAt2x = $projectRoot.'/resources/brand/tray-icon@2x.png';

if (! extension_loaded('gd')) {
    fwrite(STDERR, "regenerate_tray_icon: requires the GD extension.\n");

    exit(1);
}

if (! is_file($sourcePath)) {
    fwrite(STDERR, "regenerate_tray_icon: source {$sourcePath} not found.\n");

    exit(1);
}

$source = @imagecreatefrompng($sourcePath);

if ($source === false) {
    fwrite(STDERR, "regenerate_tray_icon: could not decode {$sourcePath}.\n");

    exit(1);
}

$srcWidth = imagesx($source);
$srcHeight = imagesy($source);

// Pixels in the source that are "background" — either transparent (low
// alpha) or very close to white. The committed app icon is rendered on a
// white background, so a pure-alpha threshold alone leaves the white field
// in the silhouette. The white-distance fallback handles that.
$alphaCutoff = 80;          // 0 = opaque, 127 = transparent (GD scale).
$whiteSimilarity = 235;     // 0..255 — pixels brighter than this are bg.

/**
 * Returns true when the source pixel at ($x, $y) is part of the
 * silhouette — opaque enough AND not a near-white background pixel.
 */
$isFigure = function (GdImage $img, int $x, int $y) use ($alphaCutoff, $whiteSimilarity): bool {
    $rgba = imagecolorat($img, $x, $y);
    $alpha = ($rgba >> 24) & 0x7F;
    $red = ($rgba >> 16) & 0xFF;
    $green = ($rgba >> 8) & 0xFF;
    $blue = $rgba & 0xFF;

    if ($alpha > $alphaCutoff) {
        return false;
    }

    if ($red >= $whiteSimilarity && $green >= $whiteSimilarity && $blue >= $whiteSimilarity) {
        return false;
    }

    return true;
};

/**
 * Render the silhouette at the given target size (square) by downsampling
 * the source with imagecopyresampled, then re-classifying every output
 * pixel against the threshold rules. Resampling first keeps anti-aliasing
 * along the silhouette boundary; the re-classification then crisps the
 * edge so the alpha channel encodes a clean shape.
 */
$render = function (int $size) use ($source, $srcWidth, $srcHeight, $isFigure): GdImage {
    $scaled = imagecreatetruecolor($size, $size);

    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);

    $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
    imagefilledrectangle($scaled, 0, 0, $size, $size, $transparent);

    // Resample the source down to the target size so each output pixel is
    // the local mean of its source neighbourhood — the smooth edge feeds
    // the threshold pass below.
    imagecopyresampled($scaled, $source, 0, 0, 0, 0, $size, $size, $srcWidth, $srcHeight);

    $output = imagecreatetruecolor($size, $size);

    imagealphablending($output, false);
    imagesavealpha($output, true);

    $bg = imagecolorallocatealpha($output, 0, 0, 0, 127);
    imagefilledrectangle($output, 0, 0, $size, $size, $bg);

    $black = imagecolorallocatealpha($output, 0, 0, 0, 0);

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($isFigure($scaled, $x, $y)) {
                imagesetpixel($output, $x, $y, $black);
            }
        }
    }

    imagedestroy($scaled);

    return $output;
};

$icon22 = $render(22);
$icon44 = $render(44);

if (! is_dir(dirname($outputBase)) && ! mkdir(dirname($outputBase), 0755, true)) {
    fwrite(STDERR, 'regenerate_tray_icon: could not create '.dirname($outputBase)."\n");

    exit(1);
}

if (! imagepng($icon22, $outputBase)) {
    fwrite(STDERR, "regenerate_tray_icon: failed to write {$outputBase}\n");

    exit(1);
}

if (! imagepng($icon44, $outputAt2x)) {
    fwrite(STDERR, "regenerate_tray_icon: failed to write {$outputAt2x}\n");

    exit(1);
}

imagedestroy($icon22);
imagedestroy($icon44);
imagedestroy($source);

fwrite(STDOUT, "regenerate_tray_icon: wrote {$outputBase} (22x22) and {$outputAt2x} (44x44).\n");

exit(0);
