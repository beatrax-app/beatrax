<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Banking;

use Carbon\CarbonImmutable;

/**
 * @link ../../../../.docs/features/ingestion/architecture.md#mt940
 */
final class SwiftDate
{
    private const int YEARS_BEFORE_CENTURY_ROLL = 50;

    private const int MONTHS_BEFORE_YEAR_ROLL = 6;

    // Public because the fixture rebaser has to read a :61: date exactly as
    // the import will read the file it writes. Its own reading put a `991231`
    // line in 2099 while Mt940Tag61Parser put it in 1999 — one line, two
    // dates, a century apart, and the fixture is only ever a parser input.
    public static function yearFor(int $twoDigitYear): int
    {
        $today = CarbonImmutable::now();
        $candidate = intdiv($today->year, 100) * 100 + $twoDigitYear;

        if ($candidate - $today->year > self::YEARS_BEFORE_CENTURY_ROLL) {
            return $candidate - 100;
        }
        if ($today->year - $candidate > self::YEARS_BEFORE_CENTURY_ROLL) {
            return $candidate + 100;
        }

        return $candidate;
    }

    // A :61: entry date carries a month and a day but no year. The two dates
    // sit days apart, so a month gap wider than half a year is the calendar
    // turning and a narrower one is not. Reading ANY later entry month as last
    // year moved a 31-01 value booked 01-02 back twelve months.
    public static function entryYearOffset(int $entryMonth, int $valueMonth): int
    {
        $gap = $entryMonth - $valueMonth;

        return match (true) {
            $gap > self::MONTHS_BEFORE_YEAR_ROLL => -1,
            $gap < -self::MONTHS_BEFORE_YEAR_ROLL => 1,
            default => 0,
        };
    }
}
