<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

use Modules\Core\Public\Services\UserDataPathService;

// Where a user's sealed identity lives, and whether it is there. One name for a
// path four callers spelled out, and the only question about an identity that
// needs no KEK, no session and no authenticated user — which is why the callers
// that must answer it before any of those exist ask here.
/**
 * @link ../../../../.docs/features/sync/device-identity-key-files.md
 */
final readonly class DeviceIdentityFile
{
    private const string DIRECTORY = 'sync/identity';

    public static function path(int $userId): string
    {
        return UserDataPathService::appPath(self::DIRECTORY.sprintf('/%s.enc', $userId));
    }

    public static function exists(int $userId): bool
    {
        return file_exists(self::path($userId));
    }
}
