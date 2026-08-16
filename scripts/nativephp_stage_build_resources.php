#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Stages committed build inputs into the NativePHP / electron-builder
 * `buildResources` directory before each desktop build.
 *
 * Runs as a `prebuild` hook (see config/nativephp.php).
 *
 * Why this is needed:
 *
 *   The canonical home for platform icons in this repo is `public/icon.*`
 *   (the in-repo brand surface — PROJECT.md D-17/D-18). The macOS Hardened
 *   Runtime entitlements file lives at `build/entitlements.mac.plist`.
 *   The tray-icon assets live at `resources/brand/tray-icon{,@2x}.png` as
 *   the monochrome black-on-transparent silhouettes macOS uses as a
 *   template image in the menu bar (D-09 / D-19).
 *
 *   electron-builder's `extraResources` block in `electron-builder.mjs`
 *   reads from `process.env.NATIVEPHP_BUILD_PATH`, which NativePHP sets to
 *   `vendor/nativephp/desktop/resources/build/` (via
 *   `ElectronServiceProvider::buildPath()`). The Electron-side main
 *   process loads tray icons at runtime from the packaged
 *   `Contents/Resources/build/` path, which is the destination electron-
 *   builder writes that source into. Staging only to
 *   `nativephp/electron/build/` (electron-builder's project-root
 *   `buildResources` dir) misses the extraResources copy entirely — that
 *   project-root dir is used for `icon.icns` / `icon.ico` discovery but
 *   does NOT contribute arbitrary files like a tray icon to the bundle.
 *
 *   `nativephp/` is gitignored and `vendor/` is rewritten by composer, so
 *   both targets must be re-staged on every build.
 *
 * The hook copies:
 *
 *   public/icon.png                       → <build>/icon.png
 *   public/icon.icns                      → <build>/icon.icns
 *   public/icon.ico                       → <build>/icon.ico
 *   build/entitlements.mac.plist          → <build>/entitlements.mac.plist
 *   resources/brand/tray-icon.png         → <build>/tray-icon.png
 *   resources/brand/tray-icon@2x.png      → <build>/tray-icon@2x.png
 *
 *   …where <build> is BOTH:
 *     - `nativephp/electron/build/`                      (electron-builder buildResources)
 *     - `vendor/nativephp/desktop/resources/build/`      (NATIVEPHP_BUILD_PATH → extraResources)
 *
 * The Electron main process — patched in via
 * `scripts/nativephp_inject_persistent_tray.php` — loads the tray icons
 * from the runtime buildPath at app launch.
 *
 * The hook is idempotent: it overwrites the staged copies on every run.
 * Files absent from the project root are skipped with a warning so a
 * partial brand-asset state still produces a build (the warning surfaces
 * which artefact is missing).
 *
 * Exit codes:
 *   0  staged (or nothing to do)
 *   1  a required build directory does not exist
 */
$projectRoot = dirname(__DIR__);
$publishedDir = $projectRoot.'/nativephp/electron';

if (! is_dir($publishedDir)) {
    fwrite(STDERR, "nativephp_stage_build_resources: {$publishedDir} does not exist — run `php artisan native:install --publish` first.\n");

    exit(1);
}

$buildDirs = [
    $publishedDir.'/build',
    $projectRoot.'/vendor/nativephp/desktop/resources/build',
];

foreach ($buildDirs as $buildDir) {
    if (! is_dir($buildDir) && ! mkdir($buildDir, 0755, true) && ! is_dir($buildDir)) {
        fwrite(STDERR, "nativephp_stage_build_resources: could not create {$buildDir}\n");

        exit(1);
    }
}

$sources = [
    $projectRoot.'/public/icon.png' => 'icon.png',
    $projectRoot.'/public/icon.icns' => 'icon.icns',
    $projectRoot.'/public/icon.ico' => 'icon.ico',
    $projectRoot.'/build/entitlements.mac.plist' => 'entitlements.mac.plist',
    // Overrides the published hook, which swallows a notarisation rejection
    // and reports success regardless.
    $projectRoot.'/build/notarize.js' => 'notarize.js',
    $projectRoot.'/resources/brand/tray-icon.png' => 'tray-icon.png',
    $projectRoot.'/resources/brand/tray-icon@2x.png' => 'tray-icon@2x.png',
];

$copies = [];
foreach ($sources as $from => $basename) {
    foreach ($buildDirs as $buildDir) {
        $copies[] = [$from, $buildDir.'/'.$basename];
    }
}

foreach ($copies as [$from, $to]) {
    if (! is_file($from)) {
        fwrite(STDERR, "nativephp_stage_build_resources: skipping (missing) {$from}\n");

        continue;
    }

    if (! copy($from, $to)) {
        fwrite(STDERR, "nativephp_stage_build_resources: failed copying {$from} → {$to}\n");

        exit(1);
    }

    fwrite(STDOUT, 'nativephp_stage_build_resources: staged '.basename($from).' → '.$to."\n");
}

exit(0);
