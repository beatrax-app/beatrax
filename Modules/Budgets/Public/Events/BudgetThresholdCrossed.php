<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Events;

/**
 * Dispatched for every envelope whose spend has crossed ITS OWN configured
 * over-budget notify threshold (Req 6, D-20, D-27, D-28). `Modules\Budgets`
 * is the sole owner of this event — it never imports the notifications
 * module's namespace; a `PersistBudgetNudge` listener living in that other
 * module (18-07 Task 2) subscribes to it via the guarded registration its
 * own service provider (18-05) already carries.
 *
 * Cloned from `Modules\Sync\Public\Events\SavedReportMutated`'s minimal
 * readonly shape.
 *
 * `$period` is the D-06 occurrence key — a canonical `Y-m-d` period-start
 * date string (the same value `Period::$start->toDateString()` produces).
 * It MUST be computed identically on every device or Req 12's convergence
 * breaks: derive it from `PeriodQuery`'s period-window algorithm applied to
 * the CURRENT instant, never from a locale-formatted label.
 *
 * `$budgetMinor` is the period's total budget base for this envelope —
 * `availableMinor + spentMinor` (because `availableMinor = assigned +
 * carriedIn + netMoved - spent`) — and `$spentMinor` is the envelope's
 * settled spend for the period. Both are signed integer minor units in
 * `$currency`, never a float.
 */
final readonly class BudgetThresholdCrossed
{
    public function __construct(
        public int $userId,
        public int $categoryId,
        public string $categoryName,
        public string $period,
        public int $thresholdPercent,
        public int $spentMinor,
        public int $budgetMinor,
        public string $currency,
    ) {}
}
