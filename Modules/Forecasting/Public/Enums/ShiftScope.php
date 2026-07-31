<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Enums;

// How far a "shift series date" mutation reaches: just the next occurrence,
// or that one and every subsequent occurrence. A typo silently collapsing to
// Next would shift only the first occurrence when the user picked all.
/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
enum ShiftScope: string
{
    case Next = 'next';

    case AllSubsequent = 'all_subsequent';
}
