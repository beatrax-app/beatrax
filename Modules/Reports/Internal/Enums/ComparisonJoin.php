<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Enums;

// How a previous-period row is matched to a current one. A group key joins two
// windows only when it names something that outlives them; a time bucket's key
// is a DATE, which two disjoint windows can never share, so an ordered series
// matches on position instead.
enum ComparisonJoin: string
{
    case Group = 'group';

    case Sequence = 'sequence';

    // What "the other window has no counterpart" means, which is not the same
    // answer twice: a category nobody spent on genuinely spent zero, while a
    // bucket the previous window never reached is unknown -- and the table
    // renders that null as an em dash rather than as "was zero then".
    public function missingCounterpartMinor(): ?int
    {
        return match ($this) {
            self::Group => 0,
            self::Sequence => null,
        };
    }
}
