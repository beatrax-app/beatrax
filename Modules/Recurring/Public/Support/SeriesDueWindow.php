<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Support;

use Modules\Ledger\Public\Dto\Period;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;

// Which approved series fall due inside a window, asked once. The dashboard
// draws the answer twice — the position summary's "upcoming" list and the fixed
// payments card's "This month" filter, stacked on one screen — and each used to
// decide for itself, over two windows 24 days apart on period_start_day 25.

// Half-open against the Period, never between() on an inclusive last day: a
// nextExpectedAt carrying a time of day falls outside a window ending at
// midnight, which is a second way for the same two answers to part.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-window-recomputed-instead-of-derived
 */
final readonly class SeriesDueWindow
{
    /**
     * @param  list<RecurringSeriesDto>  $series
     * @return list<RecurringSeriesDto> in the order given
     */
    public static function dueWithin(array $series, Period $period): array
    {
        return array_values(array_filter(
            $series,
            static fn (RecurringSeriesDto $row): bool => $row->nextExpectedAt !== null
                && ! $row->nextExpectedAt->lessThan($period->start)
                && $row->nextExpectedAt->lessThan($period->endExclusive),
        ));
    }
}
