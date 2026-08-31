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

    // Sealed identity, no key in this session. Distinct from NotEnabled
    // because one is answered by unlocking and the other by pairing.
    case Locked = 'locked';

    case NotEnabled = 'not_enabled';

    // A key-file that will not open is a state an UNLOCKED reader reaches,
    // after a restored or replaced database. Folded into Locked it would tell
    // them to unlock an app they are already inside.
    case Unreadable = 'unreadable';

    case PausedOnCellular = 'paused_on_cellular';
}
