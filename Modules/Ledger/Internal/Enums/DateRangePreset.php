<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Enums;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\CalendarSpan;
use Modules\Ledger\Public\Services\PeriodQuery;

// The four date-range shortcuts the transaction list offers. Two surfaces draw
// them — the desktop popover and the phone bottom sheet — so a defect in a
// blade that spelled them out was a defect to find and fix twice.

// Neither range is spelled here either: a month is whatever PeriodQuery says
// the reader's month is, and a year is CalendarSpan's, which is what /reports
// resolves its own two presets through.
enum DateRangePreset: string
{
    case ThisMonth = 'this_month';

    case LastMonth = 'last_month';

    case ThisYear = 'this_year';

    case LastYear = 'last_year';

    // subYear() off a day the target year does not have rolls FORWARD into the
    // year it started in, and the startOfYear() after it cannot undo that. The
    // NoOverflow variant clamps onto the target year's own last day instead.
    /**
     * @return array{0: string, 1: string} the inclusive after and before bounds, as Y-m-d
     */
    public function range(PeriodQuery $periods, CarbonImmutable $now, int $periodStartDay): array
    {
        $current = $periods->containingForDay($periodStartDay, $now);

        return self::inclusiveBounds(match ($this) {
            self::ThisMonth => $current,
            self::LastMonth => $periods->previous($current),
            self::ThisYear => CalendarSpan::year($now),
            self::LastYear => CalendarSpan::year($now->subYearNoOverflow()),
        });
    }

    public function labelKey(): string
    {
        return 'ledger::list.date_preset.'.$this->value;
    }

    /**
     * @return array<string, array{0: string, 1: string}> label key => that preset's after and before bounds
     */
    public static function rangesFrom(PeriodQuery $periods, CarbonImmutable $now, int $periodStartDay): array
    {
        $ranges = [];
        foreach (self::cases() as $preset) {
            $ranges[$preset->labelKey()] = $preset->range($periods, $now, $periodStartDay);
        }

        return $ranges;
    }

    // The list's own contract is an inclusive pair and Period is half-open, so
    // the conversion happens once here rather than at each of the four cases.
    /**
     * @return array{0: string, 1: string}
     */
    private static function inclusiveBounds(Period $period): array
    {
        return [$period->start->toDateString(), CalendarSpan::lastDayOf($period)->toDateString()];
    }
}
