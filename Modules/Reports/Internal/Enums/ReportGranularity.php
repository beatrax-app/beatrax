<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Enums;

// Quarterly is deliberately absent: TimeBucketGenerator widens to it on its own,
// but the user never selects it and a saved report never holds it. Distinct from
// the series and digest cadences that also say weekly.
enum ReportGranularity: string
{
    case Monthly = 'monthly';

    case Weekly = 'weekly';

    // Named once rather than repeated as a 'monthly' literal at each boundary
    // that can be handed nothing: the route query string and an untouched rail.
    public static function default(): self
    {
        return self::Monthly;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
