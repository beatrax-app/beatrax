<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

// Shown all at once so a long stage reads as "step 3 of 4", not as a hang.
// The backing value is the `mobile::setup.step.*` translation key.
enum SetupStep: string
{
    case Connect = 'connect';

    case Keys = 'keys';

    case Transfer = 'transfer';

    case Rebuild = 'rebuild';

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Connect, self::Keys, self::Transfer, self::Rebuild];
    }

    // Absent means the pull is working rather than waiting: the transfer step.
    public static function forBlocked(?SyncBlockedReason $blocked): self
    {
        return match ($blocked) {
            SyncBlockedReason::NoPeer, SyncBlockedReason::Unreachable, SyncBlockedReason::Retrying, SyncBlockedReason::Revoked => self::Connect,
            SyncBlockedReason::NoKeys => self::Keys,
            SyncBlockedReason::Reprojecting => self::Rebuild,
            SyncBlockedReason::Locked, null => self::Transfer,
        };
    }

    public function isBefore(self $other): bool
    {
        return array_search($this, self::ordered(), true) < array_search($other, self::ordered(), true);
    }
}
