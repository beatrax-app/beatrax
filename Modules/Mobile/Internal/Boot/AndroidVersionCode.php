<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Boot;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final class AndroidVersionCode
{
    // Android compares versionCode and nothing else, so it has to rise with
    // every release. Derived from the version rather than kept beside it:
    // two numbers meaning the same thing drift, and the one nobody reads
    // drifts first.
    private const MAJOR = 10000;

    private const MINOR = 100;

    // Google Play's own ceiling.
    private const CEILING = 2100000000;

    public static function fromVersion(string $version): ?int
    {
        $core = explode('-', ltrim($version, 'vV'), 2)[0];

        if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $core, $parts) !== 1) {
            return null;
        }

        [, $major, $minor, $patch] = $parts;

        // A minor or patch of 100 would carry into the field above it and make
        // the ordering lie — 1.0.100 and 1.1.0 would both be 10100.
        if ((int) $minor >= self::MINOR || (int) $patch >= self::MINOR) {
            return null;
        }

        $code = (int) $major * self::MAJOR + (int) $minor * self::MINOR + (int) $patch;

        return $code > 0 && $code <= self::CEILING ? $code : null;
    }

    // Read back after a package run: the template ships REPLACEMECODE and
    // RunsAndroid substitutes the resolved value on its way into Gradle.
    public static function inGradle(string $gradleContents): ?int
    {
        if (preg_match('/versionCode\s*=\s*(\d+)/', $gradleContents, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
