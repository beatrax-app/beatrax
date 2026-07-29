<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Enums;

// The time-bucket size a report is asked for (C7-R21). Quarterly is
// deliberately absent: TimeBucketGenerator widens to it on its own, but the
// user never selects it and a saved report never holds it. Distinct from the
// series and digest cadences that also say weekly.
/**
 * @link ../../../../.docs/features/reports/architecture.md
 */
enum ReportGranularity: string
{
    case Monthly = 'monthly';

    case Weekly = 'weekly';

    // What an unspecified granularity means. Named here rather than repeated
    // as a 'monthly' literal at each of the boundaries that can be handed
    // nothing — the route query string, and a control rail the user has not
    // touched.
    public static function default(): self
    {
        return self::Monthly;
    }

    // How the granularity is written in the interface, kept with the
    // vocabulary rather than spelled out at each view that shows it.
    public function label(): string
    {
        return ucfirst($this->value);
    }
}
