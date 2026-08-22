<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Enums;

// What a reader can ASK for, which is wider than what a dimension query can
// answer: net worth is a balance series the aggregator builds on its own, so it
// never reaches one. Aggregation::ReportMetric is the narrower three, and the
// two must not be merged — see the note there.
enum ReportMetricSelection: string
{
    case Spend = 'spend';

    case Income = 'income';

    case Net = 'net';

    case NetWorth = 'net_worth';

    // Named once rather than repeated as a 'spend' literal at each boundary that
    // can be handed nothing: the route query string and the builder's own rail.
    public static function default(): self
    {
        return self::Spend;
    }
}
