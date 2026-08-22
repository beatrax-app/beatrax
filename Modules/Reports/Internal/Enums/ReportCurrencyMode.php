<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Enums;

// Base converts every discovered currency into the user's base currency and
// merges same-group rows; Original never converts and yields one row per
// currency. The values reach the reader as `?ccy=` and as a stored
// saved_reports.definition key, so they cannot be renamed.
enum ReportCurrencyMode: string
{
    case Base = 'base';

    case Original = 'original';

    // Named once rather than repeated as a 'base' literal at each boundary that
    // can be handed nothing, which is the reason ReportGranularity has one.
    public static function default(): self
    {
        return self::Base;
    }
}
