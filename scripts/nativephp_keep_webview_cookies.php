<?php

declare(strict_types=1);

/*
 * Stop the Android shell wiping the session on every cold start.
 *
 * `native:install` generates a MainActivity whose initializeEnvironment()
 * calls clearAllCookies() before Laravel boots. That takes the session cookie
 * with it, so every cold launch — and Android kills a backgrounded process
 * routinely — lands the user on /login even though their session row is still
 * valid in the on-device database. The app-lock, not cookie lifetime, is what
 * actually protects the data here, so signing the user out on a process
 * restart buys nothing and costs the whole session.
 *
 * Only the call inside initializeEnvironment() is removed. The
 * clearAllCookies() function itself stays, so anything that genuinely wants a
 * clean jar (a sign-out path, a future reset action) can still call it.
 *
 * Same discipline as nativephp_grant_webview_camera.php: idempotent, runs from
 * the mobile root's post-update-cmd because native:install regenerates the
 * tree, and a missing anchor is a hard failure rather than a silent skip.
 */

$target = __DIR__.'/../mobile-app/nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt';

if (! is_file($target)) {
    fwrite(STDOUT, "nativephp_keep_webview_cookies: no Android scaffold yet — skipping.\n");
    exit(0);
}

$source = (string) file_get_contents($target);

$patched = <<<'KOTLIN'
    private fun initializeEnvironment() {
        // clearAllCookies() removed by scripts/nativephp_keep_webview_cookies.php
        // — it wiped the Laravel session on every cold start. See that file.
KOTLIN;

if (str_contains($source, 'nativephp_keep_webview_cookies.php')) {
    fwrite(STDOUT, "nativephp_keep_webview_cookies: already patched.\n");
    exit(0);
}

$anchor = "    private fun initializeEnvironment() {\n        clearAllCookies()";

if (! str_contains($source, $anchor)) {
    fwrite(STDERR, "nativephp_keep_webview_cookies: initializeEnvironment anchor not found in {$target}.\n");
    fwrite(STDERR, "The generated shell changed shape; re-check the cold-start cookie handling before shipping.\n");
    exit(1);
}

if (file_put_contents($target, str_replace($anchor, $patched, $source)) === false) {
    fwrite(STDERR, "nativephp_keep_webview_cookies: could not write {$target}.\n");
    exit(1);
}

fwrite(STDOUT, "nativephp_keep_webview_cookies: session now survives a cold start.\n");
exit(0);
