#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Disables electron-updater's differential-download path so the
 * ad-hoc-signed macOS bundle does not trip its own auto-update flow.
 *
 * Why this is needed:
 *
 *   The bundle is ad-hoc signed (see
 *   `scripts/nativephp_force_adhoc_signing.php`). electron-updater's
 *   differential-download path on macOS validates the differential
 *   archive against the running bundle's OS-level signature; ad-hoc
 *   signatures fail that check, so the auto-update aborts mid-flow.
 *
 *   Forcing a full-binary download bypasses the OS-signature check.
 *   The Ed25519 manifest signature + SHA-512 binary verification in
 *   `ElectronUpdateChannel` (see config/auto_update.php) provide the
 *   end-to-end integrity guarantee instead.
 *
 * Where the setting lives:
 *
 *   electron-builder 26 hard-removed `disableDifferentialDownload`
 *   from `MacConfiguration` (it was always actually a runtime setter
 *   on the `autoUpdater` object — older electron-builder versions
 *   silently accepted the unknown key, v26 strict-validates and
 *   aborts the build with a ValidationError).
 *
 *   The correct location is the Electron main-process JS, set on
 *   `autoUpdater` immediately after the `electron-updater` import.
 *   NativePHP's compiled plugin sets the destructure on this line:
 *
 *     const { autoUpdater } = electronUpdater;
 *
 *   so the patch inserts `autoUpdater.disableDifferentialDownload =
 *   true;` directly after that destructure inside both
 *   `electron-plugin/dist/server/api/autoUpdater.js` and
 *   `electron-plugin/dist/index.js` (each does its own destructure
 *   and uses `autoUpdater` independently).
 *
 * The patch is reapplied before every build (it is a `prebuild` hook)
 * and is idempotent: a file that already declares the setter is left
 * untouched.
 *
 * Exit codes:
 *   0  patched (or already correct) for every target file
 *   1  none of the expected target files could be located on disk
 */
$projectRoot = dirname(__DIR__);
$publishedDir = $projectRoot.'/nativephp/electron';

// Each target is a compiled JS file that imports electron-updater and
// destructures `autoUpdater` from it. We patch each one independently
// so any module that uses the `autoUpdater` reference also picks up
// the disabled flag.
$targets = [
    $publishedDir.'/electron-plugin/dist/server/api/autoUpdater.js',
    $publishedDir.'/electron-plugin/dist/index.js',
];

$existing = array_values(array_filter($targets, 'is_file'));

if ($existing === []) {
    fwrite(STDERR, "nativephp_inject_macos_update_settings: no autoUpdater destructure target found under {$publishedDir} — has `php artisan native:install --publish` run?\n");

    exit(1);
}

$injection = "\nautoUpdater.disableDifferentialDownload = true; // see scripts/nativephp_inject_macos_update_settings.php";
$destructurePattern = '/(const\s*\{\s*autoUpdater\s*\}\s*=\s*electronUpdater\s*;)/';

foreach ($existing as $path) {
    $source = file_get_contents($path);

    if ($source === false) {
        fwrite(STDERR, "nativephp_inject_macos_update_settings: could not read {$path}\n");

        exit(1);
    }

    if (str_contains($source, 'autoUpdater.disableDifferentialDownload')) {
        fwrite(STDOUT, "nativephp_inject_macos_update_settings: already set in {$path}, leaving as-is.\n");

        continue;
    }

    $patched = preg_replace($destructurePattern, '$1'.$injection, $source, 1, $count);

    if ($patched === null || $count !== 1) {
        fwrite(STDERR, "nativephp_inject_macos_update_settings: could not locate `const { autoUpdater } = electronUpdater;` in {$path}\n");

        exit(1);
    }

    if (file_put_contents($path, $patched) === false) {
        fwrite(STDERR, "nativephp_inject_macos_update_settings: could not write {$path}\n");

        exit(1);
    }

    fwrite(STDOUT, "nativephp_inject_macos_update_settings: set autoUpdater.disableDifferentialDownload = true in {$path}\n");
}

exit(0);
