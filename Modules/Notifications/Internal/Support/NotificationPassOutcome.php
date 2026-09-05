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
    public static function line(string $pass, int $emitted, int $deferred): string
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
}
