<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Enums;

// Ytd stops at today and ThisYear covers the whole calendar year, which are
// two questions and not two labels for one: a ledger holding booked-ahead rows
// answers them differently. The values reach the reader as `?period=` and as a
// stored saved_reports.definition key, so they cannot be renamed.
enum ReportPeriodPreset: string
{
    case ThisMonth = 'this_month';

    case Last3Months = 'last_3_months';

    case Last6Months = 'last_6_months';

    case Last12Months = 'last_12_months';

    case Ytd = 'ytd';

    case ThisYear = 'this_year';

    case Custom = 'custom';

    // Named once rather than repeated as a 'this_month' literal at each boundary
    // that can be handed nothing, which is the reason ReportGranularity has one.
    public static function default(): self
    {
        return self::ThisMonth;
    }
}
