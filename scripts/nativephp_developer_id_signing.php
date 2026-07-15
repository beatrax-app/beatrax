#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Configures real Developer ID macOS code signing for the NativePHP /
 * electron-builder desktop build.
 *
 * Runs as a `prebuild` hook (see config/nativephp.php), REPLACING the
 * earlier `nativephp_force_adhoc_signing.php`. It patches the generated
 * `electron-builder.mjs` so the `mac` config block signs the whole
 * bundle with an explicit Developer ID Application identity, enables the
 * Hardened Runtime, and leaves notarization to the existing
 * `build/notarize.js` afterSign hook.
 *
 * Why an EXPLICIT identity (not auto-discovery):
 *
 *   `nativephp_force_adhoc_signing.php` (the hook this replaces) existed
 *   because electron-builder, with NO `mac.identity`, auto-discovers a
 *   keychain identity and can partially sign — the app shell staying
 *   ad-hoc (empty Team ID) while the nested "Electron Framework"
 *   acquires a real Team ID. On Apple Silicon dyld then aborts at launch
 *   with mismatched Team IDs. Pinning `identity` to ONE Developer ID
 *   string makes electron-builder sign every nested component
 *   (frameworks, helpers, the shell) with that same identity + Team ID,
 *   producing a consistent, notarizable, cleanly-launching bundle. This
 *   is the paid-Developer-ID counterpart of the ad-hoc determinism the
 *   old hook provided.
 *
 * The identity string comes from the `NATIVEPHP_MAC_IDENTITY` env var
 * (kept out of committed code, same posture as the notarization creds);
 * the build fails loudly if it is unset so an unsigned bundle can never
 * slip through under this hook.
 *
 * File resolution mirrors `ElectronServiceProvider::electronPath()`:
 * prefer the published `nativephp/electron/` dir, else the vendored
 * default — patch whichever the build actually reads.
 *
 * Exit codes:
 *   0  patched, or already correct
 *   1  the electron-builder config / identity could not be resolved
 */
$projectRoot = dirname(__DIR__);

$identity = getenv('NATIVEPHP_MAC_IDENTITY') ?: null;
if ($identity === null || trim($identity) === '') {
    fwrite(STDERR, "nativephp_developer_id_signing: NATIVEPHP_MAC_IDENTITY is not set — refusing to build an unsigned macOS bundle under the Developer ID hook.\n");

    exit(1);
}
$identity = trim($identity);

// electron-builder wants ONLY the certificate common name — it prepends the
// "Developer ID Application:" type itself and hard-errors ("Please remove
// prefix …") if the full `security find-identity` string is passed. Accept
// either form in NATIVEPHP_MAC_IDENTITY and normalize to the bare name.
$identity = preg_replace('/^Developer ID Application:\s*/i', '', $identity) ?? $identity;

$publishedDir = $projectRoot.'/nativephp/electron';
$vendorDir = $projectRoot.'/vendor/nativephp/desktop/resources/electron';

$configPath = is_file($publishedDir.'/package.json')
    ? $publishedDir.'/electron-builder.mjs'
    : $vendorDir.'/electron-builder.mjs';

if (! is_file($configPath)) {
    fwrite(STDERR, "nativephp_developer_id_signing: no electron-builder.mjs found at {$configPath}\n");

    exit(1);
}

$source = file_get_contents($configPath);
if ($source === false) {
    fwrite(STDERR, "nativephp_developer_id_signing: could not read {$configPath}\n");

    exit(1);
}

// JSON-encode the identity so any quotes/spaces are safely escaped into
// the generated JS.
$identityLiteral = json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

// Already pinned to a NON-null identity string? Then a prior run of this
// hook did its job — leave it (scoped to the innermost mac: block so an
// `identity:` in an unrelated block never trips the guard).
if (preg_match('/\bmac\s*:\s*\{[^{}]*\bidentity\s*:\s*["\']/s', $source) === 1) {
    fwrite(STDOUT, "nativephp_developer_id_signing: mac.identity already pinned, leaving as-is.\n");

    exit(0);
}

$signingBlock = "identity: {$identityLiteral}, // Developer ID signing — see scripts/nativephp_developer_id_signing.php\n"
    ."        hardenedRuntime: true,\n"
    ."        gatekeeperAssess: false,\n"
    ."        entitlements: 'build/entitlements.mac.plist',";

// If a prior `nativephp_force_adhoc_signing.php` run left `identity: null`
// (possibly copied into the published scaffold from the vendored file),
// REPLACE that whole ad-hoc line with the Developer ID block instead of
// skipping — otherwise the bundle silently ships ad-hoc + fails notarization.
if (preg_match('/\bmac\s*:\s*\{[^{}]*?\K[ \t]*identity\s*:\s*null\s*,[^\n]*/s', $source) === 1) {
    $patched = preg_replace(
        '/(\bmac\s*:\s*\{[^{}]*?)[ \t]*identity\s*:\s*null\s*,[^\n]*/s',
        '$1'.$signingBlock,
        $source,
        1,
        $count
    );
} else {
    // No identity at all — inject the block as the first key of mac: { ... }.
    $patched = preg_replace('/(\bmac:\s*\{)/', "$1\n        ".$signingBlock, $source, 1, $count);
}

if ($patched === null || $count !== 1) {
    fwrite(STDERR, "nativephp_developer_id_signing: could not locate a `mac: {` block in {$configPath}\n");

    exit(1);
}

if (file_put_contents($configPath, $patched) === false) {
    fwrite(STDERR, "nativephp_developer_id_signing: could not write {$configPath}\n");

    exit(1);
}

fwrite(STDOUT, "nativephp_developer_id_signing: pinned mac.identity to {$identity} + hardened runtime.\n");

exit(0);
