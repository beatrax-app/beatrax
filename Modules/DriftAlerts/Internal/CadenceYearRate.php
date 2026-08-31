<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal;

use Modules\Recurring\Public\Enums\SeriesCadence;

// How many times a year a cadence bills. 52 is a calendar-year approximation
// chosen for integer consistency with the monthly-equivalent multiplier used
// in Recurring, not for calendar accuracy; irregular has no rate at all, and
// zero is a value callers short-circuit on rather than a number made up.
final readonly class CadenceYearRate
{
    public static function forCadence(SeriesCadence $cadence): int
    {
        return match ($cadence) {
            SeriesCadence::Weekly => 52,
            SeriesCadence::Monthly => 12,
            SeriesCadence::Quarterly => 4,
            SeriesCadence::Yearly => 1,
            SeriesCadence::Irregular => 0,
        };
    }
}
