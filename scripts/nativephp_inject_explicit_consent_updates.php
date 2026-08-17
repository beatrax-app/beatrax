#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Turns electron-updater's silent auto-update into an explicit-consent flow.
 *
 * Why this is needed:
 *
 *   NativePHP calls `autoUpdater.checkForUpdatesAndNotify()` on launch,
 *   which by default downloads the update and installs it on quit —
 *   trusting only TLS and the SHA-512 the update feed's own manifest
 *   declares. There is no publisher-identity check in that path, so a
 *   tampered feed installs a tampered build. Setting `autoDownload` and
 *   `autoInstallOnAppQuit` to false holds electron-updater at the
 *   `update-available` event: it still discovers the update (so the PHP
 *   listeners can verify the Ed25519-signed manifest and surface a banner),
 *   but nothing downloads or installs until the user consents and the
 *   binary's SHA-512 has been checked against that verified manifest.
 *
 * Where the setting lives:
 *
 *   `autoDownload` / `autoInstallOnAppQuit` are runtime setters on the
 *   `autoUpdater` object, not electron-builder config, so — exactly like
 *   the differential-download patch beside this one — they are injected
 *   into the compiled Electron main-process JS immediately after the
 *   `const { autoUpdater } = electronUpdater;` destructure.
 *
 * The patch is a `prebuild` hook, reapplied before every build, and is
 * idempotent: a file that already sets `autoDownload` is left untouched.
 *
 * Exit codes:
 *   0  patched (or already correct) for every target file
 *   1  none of the expected target files could be located on disk
 */
$projectRoot = dirname(__DIR__);
$publishedDir = $projectRoot.'/nativephp/electron';

$targets = [
    $publishedDir.'/electron-plugin/dist/server/api/autoUpdater.js',
    $publishedDir.'/electron-plugin/dist/index.js',
];

$existing = array_values(array_filter($targets, 'is_file'));

if ($existing === []) {
    fwrite(STDERR, "nativephp_inject_explicit_consent_updates: no autoUpdater destructure target found under {$publishedDir} — has `php artisan native:install --publish` run?\n");

    exit(1);
}

$injection = "\nautoUpdater.autoDownload = false;"
    ."\nautoUpdater.autoInstallOnAppQuit = false; // see scripts/nativephp_inject_explicit_consent_updates.php";
$destructurePattern = '/(const\s*\{\s*autoUpdater\s*\}\s*=\s*electronUpdater\s*;)/';

foreach ($existing as $path) {
    $source = file_get_contents($path);

    if ($source === false) {
        fwrite(STDERR, "nativephp_inject_explicit_consent_updates: could not read {$path}\n");

        exit(1);
    }

    if (str_contains($source, 'autoUpdater.autoDownload')) {
        fwrite(STDOUT, "nativephp_inject_explicit_consent_updates: already set in {$path}, leaving as-is.\n");

        continue;
    }

    $patched = preg_replace($destructurePattern, '$1'.$injection, $source, 1, $count);

    if ($patched === null || $count !== 1) {
        fwrite(STDERR, "nativephp_inject_explicit_consent_updates: could not locate `const { autoUpdater } = electronUpdater;` in {$path}\n");

        exit(1);
    }

    if (file_put_contents($path, $patched) === false) {
        fwrite(STDERR, "nativephp_inject_explicit_consent_updates: could not write {$path}\n");

        exit(1);
    }

    fwrite(STDOUT, "nativephp_inject_explicit_consent_updates: set autoDownload=false + autoInstallOnAppQuit=false in {$path}\n");
}

exit(0);
