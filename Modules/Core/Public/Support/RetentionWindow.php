<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Modules\Core\Public\Contracts\Clock;

// The one retention edge in the project, and the one clock it is measured on.
// Two jobs used to spell it separately: one asked the app clock, the other
// asked SQLite's `datetime('now')`, which is UTC, so the same documented rule
// pruned at two different moments.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-retention-cutoff-read-off-a-different-clock-than-the-column
 */
final class RetentionWindow
{
    public const int DAYS = 365;

    // Rendered in the app's own frame because the columns it is compared
    // against — notifications.created_at, transactions.created_at — are
    // written from this same clock.
    public static function cutoff(Clock $clock): string
    {
        return Instant::appLocal($clock->now()->subDays(self::DAYS));
    }
}
