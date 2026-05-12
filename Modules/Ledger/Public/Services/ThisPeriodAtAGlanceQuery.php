<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\DashboardSummary;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * The dashboard's data composer (D-17). Builds a single `DashboardSummary`
 * payload for the "this period at a glance" home view:
 *
 *   inflow / outflow / net   ← integer SUM over the period window
 *   topCategories            ← delegated to TopCategoriesByPeriodQuery
 *   recentTransactions       ← delegated to TransactionListQuery::recent
 *   uncategorizedCount       ← lifetime count (drives the top-nav badge)
 *   isFirstRun               ← user has zero transactions across all time
 *
 * The first-run flag drives the D-18 redirect: the Dashboard Livewire
 * component sends users straight to `/imports/new` until they have at
 * least one transaction.
 *
 * Money is composed only at the DTO boundary (`Money::ofMinor`) — the
 * SQL layer is integer-pure to keep the dashboard query under the 50ms
 * budget on 1k rows. The raw query builder (`DatabaseManager::table()`)
 * is used directly rather than the Eloquent Builder because the project
 * applies `phpstan-strict-rules`' `staticMethod.dynamicCall` rule which
 * forbids calls to `Builder::count()` / `Builder::orderByDesc()` / ... .
 */
final class ThisPeriodAtAGlanceQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TopCategoriesByPeriodQuery $topCategoriesQuery,
        private readonly TransactionListQuery $listQuery,
    ) {}

    public function for(User $user, Period $period): DashboardSummary
    {
        $connection = $this->db->connection();

        $totalCount = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->count();

        if ($totalCount === 0) {
            return new DashboardSummary(
                period: $period,
                inflow: Money::ofMinor(0, 'EUR'),
                outflow: Money::ofMinor(0, 'EUR'),
                net: Money::ofMinor(0, 'EUR'),
                topCategories: [],
                recentTransactions: [],
                uncategorizedCount: 0,
                isFirstRun: true,
            );
        }

        $row = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN amount_minor > 0 THEN amount_minor ELSE 0 END), 0) AS inflow_minor,
                 COALESCE(SUM(CASE WHEN amount_minor < 0 THEN -amount_minor ELSE 0 END), 0) AS outflow_minor,
                 COALESCE(SUM(amount_minor), 0) AS net_minor'
            )
            ->first();

        $inflowMinor = self::toInt($row?->inflow_minor);
        $outflowMinor = self::toInt($row?->outflow_minor);
        $netMinor = self::toInt($row?->net_minor);

        $uncategorized = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereNull('category_id')
            ->count();

        $recent = $this->listQuery->recent($user, daysBack: 90, limit: 10);

        return new DashboardSummary(
            period: $period,
            inflow: Money::ofMinor($inflowMinor, 'EUR'),
            outflow: Money::ofMinor($outflowMinor, 'EUR'),
            net: Money::ofMinor($netMinor, 'EUR'),
            topCategories: $this->topCategoriesQuery->for($user, $period, limit: 5),
            recentTransactions: $recent->rows,
            uncategorizedCount: $uncategorized,
            isFirstRun: false,
        );
    }

    /**
     * Coerces a raw query-builder scalar into an int. SUM expressions over
     * an integer column always come back as a numeric string or null in the
     * SQLite driver, so the guard keeps PHPStan's `cast.int` rule happy
     * without any data-loss risk.
     */
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
