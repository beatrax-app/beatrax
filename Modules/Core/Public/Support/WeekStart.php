<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Carbon\CarbonImmutable;

// The day a week opens on in this app, named once. The calendar's strip pinned
// Monday and the date field's picker took Carbon's locale answer; all 26
// shipped locales resolve to Monday, so they agree today and would part on the
// first Sunday-first locale added.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-window-recomputed-instead-of-derived
 */
final readonly class WeekStart
{
    public const int DAY = CarbonImmutable::MONDAY;

    private const int LAST_DAY = CarbonImmutable::SUNDAY;

    public const int DAYS_IN_WEEK = 7;

    public static function of(CarbonImmutable $day): CarbonImmutable
    {
        return $day->startOfWeek(self::DAY);
    }

    public static function endOfWeekFor(CarbonImmutable $day): CarbonImmutable
    {
        return $day->endOfWeek(self::LAST_DAY);
    }
}
