#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Applies the macOS Big Sur+ "squircle" corner-rounding to the project's
 * colored brand icon at `public/icon.png` so the bundled app icon shows
 * the standard rounded-corner shape every other macOS app uses in
 * Finder, the Dock, Launchpad, and the Cmd-Tab switcher.
 *
 * Operates in place on the existing `public/icon.png` (preserves whatever
 * artwork the brand asset already carries) and rebuilds the `.icns`
 * iconset for the macOS bundle.
 *
 * The squircle is the canonical macOS app icon shape — a superellipse
 * with exponent ≈ 5, NOT a circle-cornered rounded rectangle. The
 * difference is small but visible at large icon sizes and is what makes
 * Apple-style icons look "right" versus other rounded squares.
 *
 * Exit codes:
 *   0  rebuilt
 *   1  icon source / GD tool missing
 *   2  iconutil / sips failed
 */
const SUPERELLIPSE_EXPONENT = 5.0;
const ICONSET_SIZES = [16, 32, 64, 128, 256, 512];

/**
 * Fraction of the canvas the squircle silhouette fills. macOS Big Sur+
 * leaves a transparent margin around the icon so it sits inside the
 * Dock and Launchpad grid the same way every other native app does.
 * Apple's template (~824/1024) lands the squircle at ~80% of the canvas.
 */
const SQUIRCLE_FILL = 0.8;

$projectRoot = dirname(__DIR__);
$source = $projectRoot.'/public/icon.png';
$iconset = $projectRoot.'/.icon-build.iconset';
$icnsTarget = $projectRoot.'/public/icon.icns';

if (! is_file($source)) {
    fwrite(STDERR, "regenerate_app_icon: source {$source} not found\n");

    exit(1);
}

if (! function_exists('imagecreatefrompng')) {
    fwrite(STDERR, "regenerate_app_icon: PHP GD extension is not loaded\n");

    exit(1);
}

$src = imagecreatefrompng($source);
if ($src === false) {
    fwrite(STDERR, "regenerate_app_icon: could not decode {$source}\n");

    exit(1);
}

$w = imagesx($src);
$h = imagesy($src);
if ($w !== $h) {
    fwrite(STDERR, "regenerate_app_icon: icon must be square, got {$w}x{$h}\n");

    exit(1);
}

/*
 * Apply the squircle alpha mask. For each pixel, evaluate the
 * superellipse |x/r|^n + |y/r|^n <= 1. Pixels strictly inside keep
 * the source alpha; pixels outside the squircle go fully transparent.
 * A 1px-wide soft-edge band linearly fades the alpha so the silhouette
 * does not jag.
 */
$out = imagecreatetruecolor($w, $h);
imagealphablending($out, false);
imagesavealpha($out, true);
$transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
if ($transparent === false) {
    fwrite(STDERR, "regenerate_app_icon: could not allocate transparent base colour\n");

    exit(1);
}
imagefilledrectangle($out, 0, 0, $w - 1, $h - 1, $transparent);

/*
 * The squircle silhouette sits inside the canvas at SQUIRCLE_FILL of
 * its width — the source artwork is downscaled into that inner area
 * so the bundled icon matches the Dock/Launchpad sizing every other
 * macOS app uses.
 */
$inner = max(1, (int) round($w * SQUIRCLE_FILL));
$offset = (int) round(($w - $inner) / 2);

$scaled = imagecreatetruecolor($inner, $inner);
imagealphablending($scaled, false);
imagesavealpha($scaled, true);
$scaledTransparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
if ($scaledTransparent === false) {
    fwrite(STDERR, "regenerate_app_icon: could not allocate transparent base for scaled artwork\n");

    exit(1);
}
imagefilledrectangle($scaled, 0, 0, $inner - 1, $inner - 1, $scaledTransparent);
imagecopyresampled($scaled, $src, 0, 0, 0, 0, $inner, $inner, $w, $h);

$r = ($inner - 1) / 2.0;
$cx = $inner / 2.0;
$cy = $inner / 2.0;
$n = SUPERELLIPSE_EXPONENT;
$soft = 1.0;

for ($y = 0; $y < $inner; $y++) {
    for ($x = 0; $x < $inner; $x++) {
        $dx = abs($x + 0.5 - $cx);
        $dy = abs($y + 0.5 - $cy);
        $value = (($dx / $r) ** $n) + (($dy / $r) ** $n);

        if ($value <= 1.0) {
            $coverage = 1.0;
        } else {
            $excess = ($value - 1.0) * $r;
            if ($excess >= $soft) {
                continue;
            }
            $coverage = 1.0 - ($excess / $soft);
        }

        $rgba = imagecolorat($scaled, $x, $y);
        $srcAlpha = ($rgba >> 24) & 0x7F;
        $srcOpacity = (127 - $srcAlpha) / 127.0;
        $outOpacity = $srcOpacity * $coverage;
        $outAlpha = (int) round(127 - ($outOpacity * 127));
        $outAlpha = max(0, min(127, $outAlpha));

        $color = imagecolorallocatealpha(
            $out,
            ($rgba >> 16) & 0xFF,
            ($rgba >> 8) & 0xFF,
            $rgba & 0xFF,
            $outAlpha,
        );
        if ($color === false) {
            continue;
        }
        imagesetpixel($out, $offset + $x, $offset + $y, $color);
    }
}

imagedestroy($src);
imagedestroy($scaled);

if (! imagepng($out, $source)) {
    fwrite(STDERR, "regenerate_app_icon: could not write masked {$source}\n");

    exit(1);
}
imagedestroy($out);

fwrite(STDOUT, "regenerate_app_icon: applied squircle mask to icon.png ({$w}x{$h})\n");

/*
 * Build the iconset directory then pack it into a .icns. macOS Finder
 * picks the right tile from this multi-resolution archive based on
 * display density. The largest source is the masked icon.png; sips
 * downscales each tier.
 */
if (! is_dir($iconset) && ! mkdir($iconset, 0755, true) && ! is_dir($iconset)) {
    fwrite(STDERR, "regenerate_app_icon: could not create {$iconset}\n");

    exit(2);
}

$stalePngs = glob($iconset.'/*.png');
foreach ($stalePngs === false ? [] : $stalePngs as $stale) {
    @unlink($stale);
}

foreach (ICONSET_SIZES as $size) {
    foreach ([['', $size], ['@2x', $size * 2]] as [$suffix, $pixels]) {
        if ($pixels > $w) {
            continue;
        }
        $target = sprintf('%s/icon_%dx%d%s.png', $iconset, $size, $size, $suffix);
        $cmd = sprintf(
            'sips -z %d %d %s --out %s 2>&1',
            $pixels,
            $pixels,
            escapeshellarg($source),
            escapeshellarg($target),
        );
        exec($cmd, $out, $status);
        if ($status !== 0) {
            fwrite(STDERR, "regenerate_app_icon: sips failed for {$target} — ".implode("\n", $out)."\n");

            exit(2);
        }
    }
}

exec(sprintf(
    'iconutil -c icns -o %s %s 2>&1',
    escapeshellarg($icnsTarget),
    escapeshellarg($iconset),
), $iconutilOut, $iconutilStatus);
if ($iconutilStatus !== 0) {
    fwrite(STDERR, 'regenerate_app_icon: iconutil failed — '.implode("\n", $iconutilOut)."\n");

    exit(2);
}

exec(sprintf('rm -rf %s', escapeshellarg($iconset)));

fwrite(STDOUT, "regenerate_app_icon: rebuilt icon.icns from masked icon.png\n");

exit(0);
