<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// isFirstRun means zero transactions in any period; the route redirects to
// /imports/new on it rather than rendering an empty dashboard.
final class DashboardSummary extends Data
{
    /**
     * @param  array<TransactionRowDto>  $recentTransactions
     * @param  list<string>  $unconvertedCurrencies  codes left out of the tiles for want of a rate
     */
    public function __construct(
        public readonly Period $period,
        public readonly Money $inflow,
        public readonly Money $outflow,
        public readonly Money $net,
        public readonly TopCategories $topCategories,
        public readonly array $recentTransactions,
        public readonly int $uncategorizedCount,
        public readonly bool $isFirstRun,
        public readonly array $unconvertedCurrencies = [],
    ) {}
}
