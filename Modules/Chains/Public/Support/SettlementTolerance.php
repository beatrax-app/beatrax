<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Support;

// How far a payment may sit from the statement it settles and still be that
// settlement: EUR 5, or 2% of the statement where that is the larger, which
// absorbs a charge posted after the period closed. Both the resolver linking a
// settled transfer and the projection inferring the next one read it.
final class SettlementTolerance
{
    public const int FLOOR_MINOR = 500;

    public const int PERCENT = 2;

    public static function minorFor(int $statementMinor): int
    {
        return max(self::FLOOR_MINOR, intdiv(abs($statementMinor) * self::PERCENT, 100));
    }
}
