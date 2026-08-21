<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Support;

use Carbon\CarbonImmutable;

/**
 * @link ../../../../.docs/features/tax/tax-year-resolution.md
 */
final class FilingSeason
{
    // January-April is still spent filing the previous year's return, so the
    // year every tax surface defaults to follows the filing season and not
    // the calendar.
    public const LAST_MONTH_OF_PREVIOUS_YEARS_SEASON = 4;

    public static function defaultYear(CarbonImmutable $now): int
    {
        return $now->month <= self::LAST_MONTH_OF_PREVIOUS_YEARS_SEASON
            ? $now->year - 1
            : $now->year;
    }
}
