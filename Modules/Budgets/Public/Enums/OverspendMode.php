<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Enums;

// How an envelope handles an overspend: `reduce_to_budget` trims next
// month's ready-to-assign, `carry_negative` carries the shortfall in the
// envelope. The column stays string; this enum is the one canonical
// spelling callers map through.
enum OverspendMode: string
{
    case ReduceToBudget = 'reduce_to_budget';

    case CarryNegative = 'carry_negative';
}
