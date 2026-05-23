#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Forces deterministic ad-hoc macOS code signing for the NativePHP /
 * electron-builder desktop build.
 *
 * Runs as a `prebuild` hook (see config/nativephp.php). It patches the
 * generated `nativephp/electron/electron-builder.mjs` so the `mac`
 * config block contains `identity: null`.
 *
 * Why this is needed:
 *
 *   electron-builder's macOS signing step, when no `mac.identity` is
 *   set, auto-discovers a code-signing identity from the macOS
 *   keychain. If any identity is present (an Xcode-provisioned "Apple
 *   Development" certificate, an expired Developer ID, etc.) the bundle
 *   is partially signed: the app shell stays ad-hoc with an empty Team
 *   ID while the nested "Electron Framework.framework" keeps a
 *   non-empty Team ID. On Apple Silicon dyld aborts at launch because
 *   images mapped into one process have mismatched Team IDs
 *   ("Library not loaded ... different Team IDs").
 *
 *   Setting `identity: null` makes electron-builder always perform a
 *   consistent `--deep` ad-hoc signing pass regardless of keychain
 *   contents, producing a reproducible bundle that launches cleanly.
 *   This requires no paid Apple Developer ID — appropriate for a
 *   local-only build.
 *
 * NativePHP resolves the electron working directory the same way
 * `Native\Desktop\Drivers\Electron\ElectronServiceProvider::electronPath()`
 * does: it uses the project-local `nativephp/electron/` directory when
 * that directory has been published (it contains a `package.json`), and
 * otherwise falls back to the package default shipped inside
 * `vendor/nativephp/desktop/resources/electron/`. The build reads the
 * `electron-builder.mjs` from whichever of those paths is active, so
 * this hook must patch that exact file — patching the project-local
 * path alone is a silent no-op when the build uses the vendor fallback.
 *
 * The patch is reapplied before every build (it is a `prebuild` hook)
 * and is idempotent: a config that already declares `identity` is left
 * untouched.
 *
 * Exit codes:
 *   0  patched, or already correct
 *   1  the electron-builder config could not be located or patched
 */
$projectRoot = dirname(__DIR__);

// Resolve the electron-builder.mjs the build will actually read, mirroring
// ElectronServiceProvider::electronPath(): prefer the published project
// directory, fall back to the package default inside vendor/.
$publishedDir = $projectRoot.'/nativephp/electron';
$vendorDir = $projectRoot.'/vendor/nativephp/desktop/resources/electron';

$configPath = is_file($publishedDir.'/package.json')
    ? $publishedDir.'/electron-builder.mjs'
    : $vendorDir.'/electron-builder.mjs';

if (! is_file($configPath)) {
    // Neither the published nor the vendored electron-builder.mjs exists.
    // The build cannot run without it, so fail loudly rather than letting
    // an unsigned/mis-signed bundle slip through.
    fwrite(STDERR, "nativephp_force_adhoc_signing: no electron-builder.mjs found at {$configPath}\n");

    exit(1);
}

$source = file_get_contents($configPath);

if ($source === false) {
    fwrite(STDERR, "nativephp_force_adhoc_signing: could not read {$configPath}\n");

    exit(1);
}

// Scope the idempotency check to the `mac: { ... }` block — an
// `identity:` key anywhere else in the electron-builder config
// (`appx: { identity: ... }`, `publish: { identity: ... }`, an
// unrelated future block) must NOT cause the patch to be skipped or
// the next macOS build will silently re-acquire the partial-signing
// bug this script exists to prevent. The `[^{}]*` body restricts the
// `\bidentity\s*:` match to the innermost `mac:` block; the `/s`
// flag lets the body span newlines so the multi-line config layout
// electron-builder ships matches.
if (preg_match('/\bmac\s*:\s*\{[^{}]*\bidentity\s*:/s', $source) === 1) {
    fwrite(STDOUT, "nativephp_force_adhoc_signing: mac.identity already configured, leaving as-is.\n");

    exit(0);
}

// Insert `identity: null` as the first key of the `mac: { ... }` block.
$patched = preg_replace(
    '/(\bmac:\s*\{)/',
    "$1\n        identity: null, // forced ad-hoc signing — see scripts/nativephp_force_adhoc_signing.php",
    $source,
    1,
    $count
);

if ($patched === null || $count !== 1) {
    fwrite(STDERR, "nativephp_force_adhoc_signing: could not locate a `mac: {` block in {$configPath}\n");

    exit(1);
}

if (file_put_contents($configPath, $patched) === false) {
    fwrite(STDERR, "nativephp_force_adhoc_signing: could not write {$configPath}\n");

    exit(1);
}

fwrite(STDOUT, "nativephp_force_adhoc_signing: injected `identity: null` for deterministic ad-hoc signing.\n");

exit(0);
