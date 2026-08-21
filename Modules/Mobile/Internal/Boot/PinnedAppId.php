<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Boot;

final class PinnedAppId
{
    // Mirrors nativephp/mobile's own guard in InstallCommand::ensureAppIdIsSet()
    // exactly. When this returns true that method early-returns, so the bundle
    // id cannot be generated; a looser test would let a generated one through.
    private const PATTERN = '/^NATIVEPHP_APP_ID=(.+)$/m';

    private const UNUSABLE = ['', '""', "''"];

    public static function isPinnedIn(string $envContents): bool
    {
        if (preg_match(self::PATTERN, $envContents, $matches) !== 1) {
            return false;
        }

        return ! in_array(trim($matches[1]), self::UNUSABLE, true);
    }

    // Read only after a package run: native:install lays the project down with
    // a REPLACE_APP_ID placeholder, and prepareAndroidBuild() substitutes the
    // real one on its way into Gradle. Before that, this reads the placeholder.
    public static function inGradle(string $gradleContents): ?string
    {
        if (preg_match('/applicationId\s*=\s*"([^"]+)"/', $gradleContents, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
