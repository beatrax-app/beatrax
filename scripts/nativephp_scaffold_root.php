<?php

declare(strict_types=1);

/*
 * Where the generated native project lives, probed rather than assumed.
 *
 * In this repository the mobile scaffold sits under mobile-app/nativephp/. In a
 * materialized mobile-build tree the scripts and the scaffold share one root, so
 * a hard-coded ../mobile-app/ path finds nothing — and every script that used
 * one printed "no scaffold yet — skipping" and exited 0. A Bifrost build made
 * that way carried NativePHP's own icon, no WebView camera permission and
 * allowBackup="true", with a clean green log.
 *
 * BEATRAX_NATIVE_ROOT overrides both, for a tree in neither shape.
 */

if (! function_exists('beatraxScaffoldPath')) {
    /**
     * @param  string  $relative  path under nativephp/, e.g. 'android/app/src/main'
     * @return string|null the first existing candidate, or null when there is no
     *                     scaffold to patch
     */
    function beatraxScaffoldPath(string $relative): ?string
    {
        $override = getenv('BEATRAX_NATIVE_ROOT');

        $roots = $override === false || $override === ''
            ? [dirname(__DIR__).'/mobile-app', dirname(__DIR__)]
            : [$override];

        foreach ($roots as $root) {
            $candidate = $root.'/nativephp/'.ltrim($relative, '/');

            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

if (! function_exists('beatraxMobileVendorPath')) {
    /**
     * The mobile composer root's vendor tree, on the same terms.
     *
     * @param  string  $relative  path under vendor/, e.g. 'nativephp/mobile/src'
     */
    function beatraxMobileVendorPath(string $relative): ?string
    {
        $override = getenv('BEATRAX_NATIVE_ROOT');

        $roots = $override === false || $override === ''
            ? [dirname(__DIR__).'/mobile-app', dirname(__DIR__)]
            : [$override];

        foreach ($roots as $root) {
            $candidate = $root.'/vendor/'.ltrim($relative, '/');

            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

if (! function_exists('beatraxRewrite')) {
    /**
     * A rewrite that stopped part-way is not a rewrite that matched nothing.
     * `(string) preg_replace(...)` spells a PCRE give-up as an empty subject,
     * and these scripts write their subject straight back to disk: the manifest
     * blanks, and the verification pass that would have caught it reads the
     * blank and finds nothing left to object to.
     *
     * @param  string  $label  the script name, for whoever reads stderr
     */
    function beatraxRewrite(string $label, string $pattern, string $replacement, string $subject): string
    {
        $rewritten = preg_replace($pattern, $replacement, $subject);

        if ($rewritten === null || preg_last_error() !== PREG_NO_ERROR) {
            fwrite(STDERR, "{$label}: the pattern {$pattern} stopped part-way (".preg_last_error_msg().'). '
                ."Its subject would have been written back blank.\n");
            exit(1);
        }

        return $rewritten;
    }
}
