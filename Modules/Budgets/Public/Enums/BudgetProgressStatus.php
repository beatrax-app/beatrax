<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Enums;

// Which bucket a period's spend falls in against its budget: Under below the
// near threshold, Near up to the budget, Over past it. Computed per request
// from fractionUsed — no column stores it.
enum BudgetProgressStatus: string
{
    case Over = 'over';

    case Near = 'near';

    case Under = 'under';
}
