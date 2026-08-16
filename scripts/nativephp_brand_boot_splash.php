<?php

declare(strict_types=1);

/*
 * Brand the Android boot splash: app-coloured wash and a breathing logo.
 *
 * `native:install` generates a splash composable that fills the screen with
 * `Color.Black` and, with no splash drawable, prints "Loading…" in white at
 * the bottom. Both are wrong for this app: the shell is white / slate-950, so
 * the boot frame flashed a colour the app never uses, and the only sign of
 * life sat stranded under the content it was supposed to introduce.
 *
 * `public/splash.png` (see generate_mobile_splash.php) already supplies the
 * wash and the centred mark. Two things still need the generated Kotlin:
 *
 *   - the Box wash, which shows for the frames before the bitmap decodes, and
 *     which must match the app rather than black;
 *   - motion. The PHP runtime takes a visible beat to boot and a motionless
 *     mark reads as a frozen app.
 *
 * The motion is a slow scale "breath" on the whole bitmap, never alpha: the
 * image is drawn with ContentScale.Crop over a solid wash, so scaling only
 * ever up (1.0 → 1.04) keeps it full-bleed and the solid background makes the
 * centred logo the only part that reads as moving. Fading would blink the
 * background against the window behind it.
 *
 * The generated tree is rebuilt by `native:install`, which composer's
 * post-update-cmd re-runs, so this is applied from there rather than
 * hand-edited. It is idempotent — a second run over a patched file is a
 * no-op — and a missing anchor is a hard failure, because a silently
 * unpatched splash is exactly the state this exists to fix.
 */

$androidRoot = __DIR__.'/../mobile-app/nativephp/android/app/src/main';
$target = $androidRoot.'/java/com/nativephp/mobile/ui/MainActivity.kt';

if (! is_file($target)) {
    fwrite(STDERR, "MainActivity.kt not found: {$target}\n");

    exit(1);
}

/*
 * Install the drawable ourselves.
 *
 * `installAndroidSplashScreen()` logs "Android splash screen installed" and
 * writes nothing that survives the build — no `drawable-*dpi` directory ever
 * appears in the generated tree, so `getIdentifier("splash", "drawable", …)`
 * resolves to 0 and the composable falls through to its "Loading…" text. The
 * densities it tries to generate are not needed anyway: one density-independent
 * drawable is scaled by Android, and the composable draws it with
 * ContentScale.Crop over a matching wash, so there is nothing for a
 * per-density asset to improve.
 */
$drawables = [
    'drawable' => __DIR__.'/../public/splash.png',
    'drawable-night' => __DIR__.'/../public/splash-dark.png',
];

foreach ($drawables as $directory => $source) {
    if (! is_file($source)) {
        fwrite(STDERR, "Splash source missing: {$source} (run generate_mobile_splash.php)\n");

        exit(1);
    }

    $destinationDir = $androidRoot.'/res/'.$directory;

    if (! is_dir($destinationDir) && ! mkdir($destinationDir, 0755, true) && ! is_dir($destinationDir)) {
        fwrite(STDERR, "Could not create {$destinationDir}\n");

        exit(1);
    }

    copy($source, $destinationDir.'/splash.png');
    echo 'Installed ', $directory, '/splash.png', PHP_EOL;
}

$contents = (string) file_get_contents($target);

if (str_contains($contents, 'splash-boot-breath')) {
    echo "Boot splash composable already branded; nothing to do.\n";

    exit(0);
}

// Compose animation lives in a subpackage the generated file only imports
// `tween` from, and the scale modifier is an extension so it cannot be
// spelled inline.
$importAnchor = "import androidx.compose.animation.core.tween\n";

if (! str_contains($contents, $importAnchor)) {
    fwrite(STDERR, "Import anchor not found; refusing to patch blindly.\n");

    exit(1);
}

$contents = str_replace(
    $importAnchor,
    $importAnchor
        ."import androidx.compose.animation.core.RepeatMode\n"
        ."import androidx.compose.animation.core.animateFloat\n"
        ."import androidx.compose.animation.core.infiniteRepeatable\n"
        ."import androidx.compose.animation.core.rememberInfiniteTransition\n"
        ."import androidx.compose.ui.draw.scale\n",
    $contents,
);

$washAnchor = <<<'KOTLIN'
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(Color.Black),
            contentAlignment = Alignment.Center
        ) {
KOTLIN;

$washPatched = <<<'KOTLIN'
        // The app shell is white / slate-950; Color.Black here flashed a
        // colour the app never uses in the frames before the bitmap decodes.
        val splashWash = if (isSystemInDarkTheme()) Color(0xFF020617) else Color.White

        // Slow breath so a boot that takes a beat still reads as working.
        // Scale, never alpha: the bitmap is Crop-scaled over a solid wash, so
        // scaling only upwards keeps it full-bleed and the centred mark is the
        // only part that appears to move.
        val breathTransition = rememberInfiniteTransition(label = "splash-boot-breath")
        val breath by breathTransition.animateFloat(
            initialValue = 1f,
            targetValue = 1.04f,
            animationSpec = infiniteRepeatable(
                animation = tween(1100),
                repeatMode = RepeatMode.Reverse
            ),
            label = "splash-boot-breath-scale"
        )

        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(splashWash),
            contentAlignment = Alignment.Center
        ) {
KOTLIN;

if (! str_contains($contents, $washAnchor)) {
    fwrite(STDERR, "Splash Box anchor not found; refusing to patch blindly.\n");

    exit(1);
}

$contents = str_replace($washAnchor, $washPatched, $contents);

$imageAnchor = <<<'KOTLIN'
                    Image(
                        bitmap = bitmap,
                        contentDescription = "App splash screen",
                        modifier = Modifier.fillMaxSize(),
                        contentScale = ContentScale.Crop
                    )
KOTLIN;

$imagePatched = <<<'KOTLIN'
                    Image(
                        bitmap = bitmap,
                        contentDescription = "App splash screen",
                        modifier = Modifier
                            .fillMaxSize()
                            .scale(breath),
                        contentScale = ContentScale.Crop
                    )
KOTLIN;

if (! str_contains($contents, $imageAnchor)) {
    fwrite(STDERR, "Splash Image anchor not found; refusing to patch blindly.\n");

    exit(1);
}

$contents = str_replace($imageAnchor, $imagePatched, $contents);

file_put_contents($target, $contents);

echo "Branded the Android boot splash (wash + breathing mark).\n";
