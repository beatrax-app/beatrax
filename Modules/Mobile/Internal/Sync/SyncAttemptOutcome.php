<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

// The backing value is the `mobile::sync.result.*` translation key, so a case
// added without copy fails TheSyncButtonSaysWhatHappenedTest.
/**
 * @link ../../../../.docs/features/mobile/background-sync-cannot-hold-the-key.md
 */
enum SyncAttemptOutcome: string
{
    case Synced = 'synced';

    case Unreachable = 'unreachable';

    // The peer answered and the Noise session did not open. Distinct from
    // Unreachable because the connection succeeded: telling this reader to
    // check their network names a cause the dial already ruled out.
    case NotSecured = 'not_secured';

    // Sealed identity, no key in this session. Distinct from NotEnabled
    // because one is answered by unlocking and the other by pairing.
    case Locked = 'locked';

    case NotEnabled = 'not_enabled';

    // A key-file that will not open is a state an UNLOCKED reader reaches,
    // after a restored or replaced database. Folded into Locked it would tell
    // them to unlock an app they are already inside.
    case Unreadable = 'unreadable';

    case PausedOnCellular = 'paused_on_cellular';

    // Whether a walk over the confirmed peers learns anything from the next
    // one. A sync happened, or the answer was about THIS device rather than
    // that peer — an identity that will not open, a policy gate — and every
    // remaining peer answers it the same way.
    public function endsTheWalk(): bool
    {
        return match ($this) {
            self::Unreachable, self::NotSecured => false,
            self::Synced, self::Locked, self::NotEnabled, self::Unreadable, self::PausedOnCellular => true,
        };
    }
}
