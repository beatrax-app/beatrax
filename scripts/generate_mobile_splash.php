<?php

declare(strict_types=1);

/*
 * Generate the mobile boot splash from the app icon.
 *
 * `native:install` looks for `public/splash.png` and `public/splash-dark.png`
 * and fans them out into the Android drawable density buckets. With neither
 * present, MainActivity's splash composable falls through to its built-in
 * `SplashText()` — the literal string "Loading…" in white, bottom-aligned, on
 * a black box. That is what the device showed: black, off-brand, and stranded
 * at the bottom of the screen while the PHP runtime boots.
 *
 * The canvas is a solid app background with the icon centred, so the splash
 * is continuous with the first rendered frame instead of flashing black and
 * then white. Both themes are emitted because the composable picks the
 * variant from the system dark-mode setting.
 *
 * Committed as image files rather than generated during the build: it already
 * runs the icon pipeline, and a splash that silently fails to render is worse
 * than one checked in and reviewable. Re-run this after changing the icon or
 * either background colour.
 */

// Matched to the app shell, not picked by eye: white is the `bg-white` page
// wash and #020617 is `--color-bg` in resources/css/app.css (slate-950).
const LIGHT_BACKGROUND = [0xFF, 0xFF, 0xFF];
const DARK_BACKGROUND = [0x02, 0x06, 0x17];

// The composable draws the bitmap with ContentScale.Crop, so a canvas near a
// modern phone's aspect keeps the centred icon well clear of the crop on both
// taller and shorter screens.
const CANVAS_WIDTH = 1080;
const CANVAS_HEIGHT = 2340;

// A third of the width reads as a brand mark rather than a hero image, and
// leaves the icon untouched by cropping on any plausible aspect.
const ICON_WIDTH = 360;

$iconPath = __DIR__.'/../public/icon.png';

if (! is_file($iconPath)) {
    fwrite(STDERR, "Icon not found: {$iconPath}\n");

    exit(1);
}

$icon = imagecreatefrompng($iconPath);

if ($icon === false) {
    fwrite(STDERR, "Could not decode icon: {$iconPath}\n");

    exit(1);
}

$iconWidth = imagesx($icon);
$iconHeight = imagesy($icon);
$targetHeight = (int) round(ICON_WIDTH * ($iconHeight / $iconWidth));

/**
 * @param  array{int, int, int}  $background
 */
function renderSplash(GdImage $icon, array $background, string $destination, int $targetHeight): void
{
    $canvas = imagecreatetruecolor(CANVAS_WIDTH, CANVAS_HEIGHT);

    $fill = imagecolorallocate($canvas, $background[0], $background[1], $background[2]);
    imagefilledrectangle($canvas, 0, 0, CANVAS_WIDTH, CANVAS_HEIGHT, $fill);

    // Alpha blending on, so a transparent icon composites against the wash
    // instead of punching a black rectangle through it.
    imagealphablending($canvas, true);

    imagecopyresampled(
        $canvas,
        $icon,
        (int) ((CANVAS_WIDTH - ICON_WIDTH) / 2),
        (int) ((CANVAS_HEIGHT - $targetHeight) / 2),
        0,
        0,
        ICON_WIDTH,
        $targetHeight,
        imagesx($icon),
        imagesy($icon),
    );

    imagepng($canvas, $destination);

    echo 'Wrote ', $destination, PHP_EOL;
}

renderSplash($icon, LIGHT_BACKGROUND, __DIR__.'/../public/splash.png', $targetHeight);
renderSplash($icon, DARK_BACKGROUND, __DIR__.'/../public/splash-dark.png', $targetHeight);
