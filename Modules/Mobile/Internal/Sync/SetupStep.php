<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

// The ordered stages of onboarding a joined device, so the setup screen can
// show which are done, which is running, and what is still to come. The
// backing value is the `mobile::setup.step.*` translation key.
/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
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

    // Which step a blocked reason belongs to. Absent means the pull is
    // working rather than waiting, which is the transfer step.
    public static function forBlocked(?SyncBlockedReason $blocked): self
    {
        return match ($blocked) {
            SyncBlockedReason::NoPeer, SyncBlockedReason::Unreachable, SyncBlockedReason::Retrying => self::Connect,
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
