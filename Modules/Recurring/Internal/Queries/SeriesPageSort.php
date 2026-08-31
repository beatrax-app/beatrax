<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Queries;

// Which order a paged series query walks in. The keyset cursor has to compose
// the same expression the ORDER BY uses, so the two live on one case rather
// than in two string comparisons that can drift apart.
enum SeriesPageSort
{
    case NewestFirst;

    // Magnitude, not the signed integer: an expense's monthly equivalent is
    // negative, so a plain DESC put the smallest expense at the top of a list
    // that says it is showing the biggest.
    case LargestMonthlyEquivalentFirst;
}
