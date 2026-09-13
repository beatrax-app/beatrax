<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Modules\Core\Public\Support\CountedUsers;

// What a scheduled pass actually did, said out loud. A run that deferred every
// user reads at the console exactly like one that had nothing to send, and on
// an install with encryption at rest EVERY OS-scheduled run takes the deferring
// branch — so silence there is the normal case, not the rare one.
/**
 * @link ../../../../.docs/features/mobile/background-sync-cannot-hold-the-key.md#the-scheduled-passes-that-cannot-write-either
 */
final class NotificationPassOutcome
{
    public static function line(string $pass, int $emitted, int $deferred, int $failed = 0): string
    {
        return self::emitted($pass, $emitted, $deferred).self::failed($failed);
    }

    private static function emitted(string $pass, int $emitted, int $deferred): string
    {
        if ($deferred === 0) {
            return sprintf('%s: emitted for %s.', $pass, CountedUsers::of($emitted));
        }

        return sprintf(
            '%s: emitted for %s, deferred for %s — this process holds no app-lock key, so the next unlocked request derives theirs.',
            $pass,
            CountedUsers::of($emitted),
            CountedUsers::of($deferred),
        );
    }

    // The count a reader of this line most needs and the one it never carried:
    // a pass that threw for somebody is not the same answer as a pass with
    // nothing to send them, and the tallies above cannot tell them apart.
    private static function failed(int $failed): string
    {
        return $failed === 0
            ? ''
            : sprintf(' Could not finish for %s — each one is logged.', CountedUsers::of($failed));
    }
}
