<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Testing;

use Modules\Sync\Internal\Identity\DeviceIdentityFile;

// The sanctioned seam for another module's tests to put an identity key-file
// where the gates look for one. Without it every suite needing an enabled
// device imports Sync\Internal's path, which the module-boundary rule forbids.
// Only presence is read here; a caller needing a usable identity mints one.
final class DeviceIdentityTestHarness
{
    /** @var list<string> */
    private static array $written = [];

    public static function place(int $userId): void
    {
        $path = DeviceIdentityFile::path($userId);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        file_put_contents($path, 'sealed elsewhere; this gate asks only whether it is here');

        self::$written[] = $path;
    }

    // RefreshDatabase resets the database and not the filesystem, and user ids
    // are reused between tests, so one of these left behind answers for
    // whoever gets that id next — in this file or in any other.
    public static function forgetAll(): void
    {
        foreach (self::$written as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        self::$written = [];
    }
}
