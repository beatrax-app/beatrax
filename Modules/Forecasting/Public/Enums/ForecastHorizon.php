<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Enums;

// How far ahead a projection runs. The backing value is the day count the
// `horizon_days` column stores and the rail posts, named once here rather than
// re-spelled by the job that validates it, the exception that reports it, the
// views that offer it and the neighbour asking for a year of balances.
enum ForecastHorizon: int
{
    case OneMonth = 30;

    case TwoMonths = 60;

    case ThreeMonths = 90;

    case SixMonths = 180;

    case OneYear = 365;

    /** @return list<int> */
    public static function days(): array
    {
        return array_column(self::cases(), 'value');
    }

    // Folded rather than max(days()): an enum is not provably non-empty to the
    // analyser, and a docblock asserting that it is would be a promise this
    // file stops keeping the day the last case is removed.
    public static function longestDays(): int
    {
        $longest = 0;
        foreach (self::cases() as $case) {
            $longest = max($longest, $case->value);
        }

        return $longest;
    }

    // The vocabulary written out for a reader — an exception message, never
    // copy — so a case added above cannot leave a stale list behind it.
    public static function spelledOut(): string
    {
        return '['.implode(', ', self::days()).']';
    }
}
