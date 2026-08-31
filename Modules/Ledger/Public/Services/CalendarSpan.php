<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\Period;

// The whole of the calendar month or the calendar year a day falls in, as the
// half-open Period every window here is carried in. This is what a reader means
// by "this year", and it is not what they mean by "year to date": one formula
// served both, and a report headed "2026" answered over eight months of it.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-window-recomputed-instead-of-derived
 */
final readonly class CalendarSpan
{
    // Labelled by the year alone, the way the picker names it. A caller that
    // wants "Year to date" is asking a different question and says so.
    public static function year(CarbonImmutable $anchor): Period
    {
        $start = $anchor->startOfYear()->startOfDay();

        return new Period(
            start: $start,
            endExclusive: $start->addYearNoOverflow(),
            label: (string) $start->year,
        );
    }

    public static function month(CarbonImmutable $anchor): Period
    {
        $start = $anchor->startOfMonth()->startOfDay();

        return new Period(
            start: $start,
            endExclusive: $start->addMonthNoOverflow(),
            label: $start->translatedFormat('F Y'),
        );
    }

    // The last day inside a half-open window, for a caller whose own contract
    // is an inclusive pair. Spelled here rather than at each of them, because a
    // window converted one way at one end and another way at the other is how
    // two surfaces come to disagree about a single day.
    public static function lastDayOf(Period $period): CarbonImmutable
    {
        return $period->endExclusive->subDay()->startOfDay();
    }
}
