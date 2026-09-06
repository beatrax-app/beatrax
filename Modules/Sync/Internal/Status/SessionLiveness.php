<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Status;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Enums\Duration;
use Throwable;

// How often a live session stamps its row, and how long a reader goes on
// believing that stamp. The two belong in one place because the second is the
// first multiplied out: a bound picked without the cadence beside it is either
// a reader that buries a live session or one that never gives up a dead one.
final class SessionLiveness
{
    // A live session stamps at most once per interval, and the responder drops
    // a peer that has sent nothing for one. A row that has missed the stamp,
    // the drop, and one more interval has no process behind it to write closed.
    private const int MISSED_STAMPS_TOLERATED = 3;

    public static function stampIntervalSeconds(): int
    {
        return Duration::Minute->seconds();
    }

    public static function staleAfterSeconds(): int
    {
        return self::stampIntervalSeconds() * self::MISSED_STAMPS_TOLERATED;
    }

    // An absent or unreadable stamp is not recent. A row nothing can date is
    // not evidence that anything is happening, and reading it as live is the
    // belief this class exists to bound.
    public static function isStampRecent(?string $lastSeenAt, CarbonImmutable $now): bool
    {
        if ($lastSeenAt === null || $lastSeenAt === '') {
            return false;
        }

        try {
            $stamped = CarbonImmutable::parse($lastSeenAt);
        } catch (Throwable) {
            return false;
        }

        return $now->diffInSeconds($stamped, absolute: true) < self::staleAfterSeconds();
    }
}
