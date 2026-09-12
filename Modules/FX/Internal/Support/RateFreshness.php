<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Support;

use Carbon\CarbonImmutable;

// How old a rate may be before a reader is warned about it, and the one place
// that comparison is made. Three calendar days, calibrated to the ECB weekend
// gap: Friday to Monday is three days, so a Monday-morning read of Friday's
// rate is not stale.
final class RateFreshness
{
    public const int STALE_DAYS_THRESHOLD = 3;

    // Absolute, because a historical lookup falls FORWARD where the table holds
    // nothing on or before the day: a rate published a month after the figure
    // it prices is as far from it as one published a month before.
    public static function isStale(?CarbonImmutable $asOf, CarbonImmutable $pricedFor): bool
    {
        return $asOf !== null
            && abs($asOf->startOfDay()->diffInDays($pricedFor->startOfDay())) > self::STALE_DAYS_THRESHOLD;
    }
}
