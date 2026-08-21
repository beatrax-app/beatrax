<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Enums;

// Backed so a typo cannot silently collapse to Next and shift one occurrence
// when the user asked for every subsequent one.
enum ShiftScope: string
{
    case Next = 'next';

    case AllSubsequent = 'all_subsequent';
}
