#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Expose which safeStorage backend Chromium settled on, which NativePHP does not.
 *
 * `System::canEncrypt()` is `safeStorage.isEncryptionAvailable()` and nothing
 * more. On macOS and Windows that is the whole answer: Keychain Services and
 * DPAPI are the only backends there. On Linux it is true for `basic_text` as
 * well — the fallback whose key is derived from a password published in
 * Chromium's own source — so a desktop with no keyring answered exactly like
 * one with GNOME Keyring, and `SafeStorageSecretShield::protectsAtRest()`
 * reported machine-bound protection that was not there. Biometric enrolment
 * writes a wrap of the app-lock data key on the strength of that answer.
 *
 * Electron 38 has `safeStorage.getSelectedStorageBackend()` and NativePHP 2.2
 * surfaces no route to it, so this hook adds one beside the endpoints it
 * already serves. `Modules\Desktop\Internal\Native\SafeStorageBackendProbe` is
 * the reader; a bundle built before this hook existed, or one whose hook left
 * its red line in the build log, answers 404 and the probe fails closed.
 *
 * Same discipline as its siblings: idempotent, marker-guarded, and a missing
 * anchor is a hard failure rather than a silent skip.
 *
 * Exit codes:
 *   0  injected, or already present
 *   1  the target file or its anchor could not be found
 */

/**
 * Marks the injection so a re-run leaves it alone.
 */
const BEATRAX_SAFE_STORAGE_BACKEND_MARKER = '// ── Beatrax safeStorage backend ──';

/**
 * The route segment, which must stay the spelling
 * `SafeStorageBackendProbe::BACKEND_ROUTE` asks for.
 */
const BEATRAX_SAFE_STORAGE_BACKEND_ROUTE = 'storage-backend';

/**
 * @return array{0: string, 1: string} the source, and one of
 *                                     `patched` / `already-present` / `anchor-missing`
 */
function injectSafeStorageBackendRoute(string $source): array
{
    if (str_contains($source, BEATRAX_SAFE_STORAGE_BACKEND_MARKER)) {
        return [$source, 'already-present'];
    }

    $anchor = 'export default router;';

    if (! str_contains($source, $anchor)) {
        return [$source, 'anchor-missing'];
    }

    $route = BEATRAX_SAFE_STORAGE_BACKEND_ROUTE;
    $marker = BEATRAX_SAFE_STORAGE_BACKEND_MARKER;

    // `null` on every non-Linux platform rather than the backend name, because
    // the reader only has a question there is a wrong answer to on Linux, and
    // the typeof guard keeps an older Electron from throwing into the 500 that
    // the reader would have to tell apart from a real refusal.
    $injection = <<<JS
        {$marker}
        router.get('/{$route}', (req, res) => {
            res.json({
                result: process.platform === 'linux' && typeof safeStorage.getSelectedStorageBackend === 'function'
                    ? safeStorage.getSelectedStorageBackend()
                    : null,
            });
        });

        JS;

    return [str_replace($anchor, $injection.$anchor, $source), 'patched'];
}

$isDirectlyInvoked = PHP_SAPI === 'cli'
    && isset($_SERVER['argv'][0])
    && realpath((string) $_SERVER['argv'][0]) === __FILE__;

if ($isDirectlyInvoked) {
    $target = dirname(__DIR__).'/nativephp/electron/electron-plugin/dist/server/api/system.js';

    if (! is_file($target)) {
        fwrite(STDERR, "nativephp_inject_safe_storage_backend: {$target} not found — has `php artisan native:install --publish` run?\n");

        exit(1);
    }

    [$patched, $status] = injectSafeStorageBackendRoute((string) file_get_contents($target));

    if ($status === 'anchor-missing') {
        fwrite(STDERR, "nativephp_inject_safe_storage_backend: no `export default router;` in {$target}.\n");
        fwrite(STDERR, "The compiled plugin changed shape; safeStorage backend detection is NOT in this build and Linux will report protection it does not have.\n");

        exit(1);
    }

    if ($status === 'patched' && file_put_contents($target, $patched) === false) {
        fwrite(STDERR, "nativephp_inject_safe_storage_backend: could not write {$target}\n");

        exit(1);
    }

    fwrite(STDOUT, "nativephp_inject_safe_storage_backend: {$status} in {$target}\n");

    exit(0);
}
