<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Modules\Auth\Public\Contracts\ColdStartVault;

// The default on web, CI, and any desktop build without a biometric gate.
// Bound so the lock screen can always inject the contract rather than
// branching on whether a platform implementation happens to be registered.
final class NullColdStartVault implements ColdStartVault
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function isEnrolled(int $userId): bool
    {
        return false;
    }

    public function enroll(int $userId, string $dataKey): bool
    {
        return false;
    }

    public function recover(int $userId, string $reason): ?string
    {
        return null;
    }

    public function forget(int $userId): void
    {
        // Nothing was ever stored, so there is nothing to clear. Disabling
        // cold-start unlock calls this on every platform, and a throw here
        // would turn "the feature is absent" into an error the settings
        // screen has to handle.
    }
}
