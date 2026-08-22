<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Enums;

// What a report groups by. ReportAggregator dispatches on these and throws on
// anything else, so a value reaching it has already been through the vocabulary
// gate in Support\ReportVocabulary.
enum ReportDimension: string
{
    case Category = 'category';

    case TimeBucket = 'time_bucket';

    case Counterparty = 'counterparty';

    case Account = 'account';

    // Named once rather than repeated as a 'category' literal at each boundary
    // that can be handed nothing, which is the reason ReportGranularity has one.
    public static function default(): self
    {
        return self::Category;
    }
}
