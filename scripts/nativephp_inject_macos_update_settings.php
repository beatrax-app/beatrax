#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Injects the auto-update settings the macOS bundle needs into the
 * generated `nativephp/electron/electron-builder.mjs`.
 *
 * Settings injected (into the `mac: { ... }` block):
 *
 *   - `disableDifferentialDownload: true` — electron-updater's
 *     differential download path on macOS validates the differential
 *     archive against the running bundle's OS-level signature. The
 *     bundle is ad-hoc-signed (see
 *     `scripts/nativephp_force_adhoc_signing.php`), so any OS-signature
 *     validation fails and the auto-update aborts. Disabling the
 *     differential path forces a full-binary download — slightly
 *     larger transfer, but the Ed25519 manifest signature +
 *     SHA-512 binary verification in ElectronUpdateChannel provide
 *     the end-to-end integrity guarantee instead.
 *
 * The patch is reapplied before every build (it is a `prebuild` hook)
 * and is idempotent: a `mac` block that already declares the key is
 * left untouched.
 *
 * Hook composition: this script runs AFTER
 * `nativephp_force_adhoc_signing.php` so the `mac:` block is
 * guaranteed to exist by the time this script looks for it.
 *
 * Exit codes:
 *   0  patched, or already correct
 *   1  the electron-builder config could not be located or patched
 */
$projectRoot = dirname(__DIR__);

// Resolve the electron-builder.mjs the build will actually read,
// mirroring the ad-hoc-signing hook's resolution rule.
$publishedDir = $projectRoot.'/nativephp/electron';
$vendorDir = $projectRoot.'/vendor/nativephp/desktop/resources/electron';

$configPath = is_file($publishedDir.'/package.json')
    ? $publishedDir.'/electron-builder.mjs'
    : $vendorDir.'/electron-builder.mjs';

if (! is_file($configPath)) {
    fwrite(STDERR, "nativephp_inject_macos_update_settings: no electron-builder.mjs found at {$configPath}\n");

    exit(1);
}

$source = file_get_contents($configPath);

if ($source === false) {
    fwrite(STDERR, "nativephp_inject_macos_update_settings: could not read {$configPath}\n");

    exit(1);
}

// Idempotency guard scoped to the `mac: { ... }` block.
if (preg_match('/\bmac\s*:\s*\{[^{}]*\bdisableDifferentialDownload\s*:/s', $source) === 1) {
    fwrite(STDOUT, "nativephp_inject_macos_update_settings: mac.disableDifferentialDownload already configured, leaving as-is.\n");

    exit(0);
}

// Insert `disableDifferentialDownload: true` as the first key of the
// `mac: { ... }` block. The ad-hoc-signing hook injects an
// `identity: null` line in the same position; preg_replace with a
// limit of 1 means each hook patches the first match independently,
// and the resulting block carries both keys side-by-side.
$patched = preg_replace(
    '/(\bmac:\s*\{)/',
    "$1\n        disableDifferentialDownload: true, // see scripts/nativephp_inject_macos_update_settings.php",
    $source,
    1,
    $count
);

if ($patched === null || $count !== 1) {
    fwrite(STDERR, "nativephp_inject_macos_update_settings: could not locate a `mac: {` block in {$configPath}\n");

    exit(1);
}

if (file_put_contents($configPath, $patched) === false) {
    fwrite(STDERR, "nativephp_inject_macos_update_settings: could not write {$configPath}\n");

    exit(1);
}

fwrite(STDOUT, "nativephp_inject_macos_update_settings: injected `disableDifferentialDownload: true` so full-binary auto-update bypasses OS-signature validation.\n");

exit(0);
