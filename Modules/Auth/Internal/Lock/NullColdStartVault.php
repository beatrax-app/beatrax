<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Modules\Auth\Public\Contracts\ColdStartVault;

// The default on web, CI and any desktop build without a biometric gate, so
// the lock screen injects the contract rather than branching on its absence.
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

    // True rather than false: nothing durable was ever held, so there is
    // nothing left holding a key. A false here would put a refusal on screen
    // on every platform that has no vault at all.
    public function forget(int $userId): bool
    {
        // Disabling cold-start unlock calls this on every platform, so a throw
        // would make "the feature is absent" an error settings must handle.
        return true;
    }
}
