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
