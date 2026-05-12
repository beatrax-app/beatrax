<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * The composed "this period at a glance" payload. Built by
 * `ThisPeriodAtAGlanceQuery::for()` and consumed by the dashboard Blade.
 *
 * `isFirstRun` is true exactly when the user has zero transactions across
 * all periods — the dashboard route uses it to redirect to `/imports/new`
 * per D-18.
 */
final class DashboardSummary extends Data
{
    /**
     * @param  array<TopCategoryRow>  $topCategories
     * @param  array<TransactionRowDto>  $recentTransactions
     */
    public function __construct(
        public readonly Period $period,
        public readonly Money $inflow,
        public readonly Money $outflow,
        public readonly Money $net,
        public readonly array $topCategories,
        public readonly array $recentTransactions,
        public readonly int $uncategorizedCount,
        public readonly bool $isFirstRun,
    ) {}
}
