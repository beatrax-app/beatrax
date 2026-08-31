<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Support;

use Carbon\CarbonImmutable;

// The window every "12m" figure on a counterparty is taken over: twelve whole
// calendar months ending with the one in progress. The headline total, the
// per-month average, the sparkline's bars and the profile's category breakdown
// are decompositions of each other, so they read one window from here.

// Counted as a rolling year on one side, the total held days no bar could
// draw: on the 1st of a month, the whole of the month a year back.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-window-recomputed-instead-of-derived
 */
final readonly class RollingTwelveMonths
{
    public const int MONTHS = 12;

    /**
     * @return list<string> twelve `Y-m` keys, oldest first — the last is the current month
     */
    public static function months(CarbonImmutable $now): array
    {
        $months = [];
        $cursor = self::start($now);
        for ($i = 0; $i < self::MONTHS; $i++) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    // Compared against a DATE column, so a bare Y-m-d and not a datetime: in
    // SQLite '2026-04-17' >= '2026-04-17 00:00:00' is FALSE, and the boundary
    // day would drop out of the very window this exists to pin.
    public static function startDate(CarbonImmutable $now): string
    {
        return self::start($now)->toDateString();
    }

    // subMonths() off a day the target month does not have rolls FORWARD into
    // the month after it, and a later startOfMonth() cannot undo that: on 31
    // January the twelve buckets ended on a February that had not happened.
    private static function start(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subMonthsNoOverflow(self::MONTHS - 1)->startOfMonth();
    }
}
