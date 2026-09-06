<?php

declare(strict_types=1);

namespace Tests\Support;

use Modules\Core\Internal\Console\InstallCommand;

// Three overrides keep the developer's real machine out of the test: plists go
// to a sandbox directory instead of ~/Library/LaunchAgents, launchctl is
// recorded rather than run, and the host's OS is answered by the test rather
// than by the runner it happens to be on.
//
// It sits here rather than at the top of the test that drives it because a
// class declared in a Pest file is reachable only while that file is loaded:
// Composer skips it, a second file naming it fatals, and two such declarations
// in one shard take the whole parallel run down before a test reports.
final class CaptureBootstrapInstallCommand extends InstallCommand
{
    /** @var list<array{uid: int, plistPath: string}> */
    public static array $capturedBootstraps = [];

    public static ?string $sandboxDir = null;

    public static bool $hostIsMacOs = true;

    protected function hostIsMacOs(): bool
    {
        return self::$hostIsMacOs;
    }

    protected function resolveLaunchAgentsDir(string $home): string
    {
        if (self::$sandboxDir === null) {
            return parent::resolveLaunchAgentsDir($home);
        }

        return self::$sandboxDir;
    }

    protected function bootstrapPlist(int $uid, string $plistPath): int
    {
        self::$capturedBootstraps[] = ['uid' => $uid, 'plistPath' => $plistPath];

        return 0;
    }
}
