<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Enums;

// `reduce_to_budget` trims next month's ready-to-assign; `carry_negative` keeps
// the shortfall in the envelope. The column stays a string, so this enum is the
// one canonical spelling callers map through.
enum OverspendMode: string
{
    case ReduceToBudget = 'reduce_to_budget';

    case CarryNegative = 'carry_negative';

    // Who absorbs a negative envelope at the period boundary. The fold used to
    // ask whether the mode equalled the DEFAULT, which is only the same question
    // while the default happens to be this case.
    public function absorbsShortfallIntoPool(): bool
    {
        return $this === self::ReduceToBudget;
    }
}
