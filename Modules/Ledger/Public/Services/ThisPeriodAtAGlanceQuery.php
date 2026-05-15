<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\DashboardSummary;
use Modules\Ledger\Public\Dto\PerCurrencyTile;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

/**
 * The dashboard's data composer. Builds a single `DashboardSummary` payload
 * for the "this period at a glance" home view:
 *
 *   inflow / outflow / net   ← integer SUM over the period window, scoped
 *                              to one display currency
 *   topCategories            ← delegated to TopCategoriesByPeriodQuery
 *   recentTransactions       ← delegated to TransactionListQuery::recent
 *   uncategorizedCount       ← lifetime count (drives the top-nav badge)
 *   isFirstRun               ← user has zero transactions across all time
 *
 * The first-run flag drives the redirect: the route handler sends users
 * straight to `/imports/new` until they have at least one transaction.
 *
 * Money totals aggregate `settled_amount_minor` filtered by
 * `settled_currency = $displayCurrency`. Multi-currency users see a
 * single-currency total rather than a silently summed mix; non-display
 * currencies are deferred to a future per-currency breakdown panel. The
 * `recentTransactions` panel applies the same `settled_currency` filter
 * so every panel on the dashboard agrees on the currency in view.
 *
 * Money is composed only at the DTO boundary (`Money::ofMinor`) — the SQL
 * layer is integer-pure to keep the dashboard query under the 50ms budget
 * on 1k rows. The raw query builder (`DatabaseManager::table()`) is used
 * directly rather than the Eloquent Builder because the project applies
 * `phpstan-strict-rules`' `staticMethod.dynamicCall` rule which forbids
 * calls to `Builder::count()` / `Builder::orderByDesc()` / ... .
 */
final class ThisPeriodAtAGlanceQuery
{
    public const DEFAULT_DISPLAY_CURRENCY = 'EUR';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TopCategoriesByPeriodQuery $topCategoriesQuery,
        private readonly TransactionListQuery $listQuery,
    ) {}

    public function for(User $user, Period $period, string $displayCurrency = self::DEFAULT_DISPLAY_CURRENCY): DashboardSummary
    {
        $connection = $this->db->connection();

        $totalCount = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->count();

        if ($totalCount === 0) {
            return new DashboardSummary(
                period: $period,
                inflow: Money::ofMinor(0, $displayCurrency),
                outflow: Money::ofMinor(0, $displayCurrency),
                net: Money::ofMinor(0, $displayCurrency),
                topCategories: [],
                recentTransactions: [],
                uncategorizedCount: 0,
                isFirstRun: true,
            );
        }

        // Inflow / outflow rollups filter by `transactions.type`, NOT by
        // amount sign — the subtractive income rule. A `transfer_in`
        // row carries a positive amount but is an internal move between
        // own accounts and MUST NOT inflate the income tile. Symmetric
        // on the expense side: `transfer_out` carries a negative amount
        // but stays out of the expense tile. Refunds, fees, and
        // adjustments are likewise excluded — only the two canonical
        // "money truly flowing in / out" types feed the tiles.
        $row = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('settled_currency', $displayCurrency)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type = 'income' THEN settled_amount_minor ELSE 0 END), 0) AS inflow_minor,
                 COALESCE(SUM(CASE WHEN type = 'expense' THEN -settled_amount_minor ELSE 0 END), 0) AS outflow_minor,
                 COALESCE(SUM(CASE WHEN type IN ('income', 'expense') THEN settled_amount_minor ELSE 0 END), 0) AS net_minor"
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

        // Pass $displayCurrency through so the "Recent transactions" panel
        // stays consistent with the currency-scoped tiles and Top Categories
        // panel — a EUR view never surfaces USD/JPY rows in the recent list.
        $recent = $this->listQuery->recent($user, daysBack: 90, limit: 10, currency: $displayCurrency);

        return new DashboardSummary(
            period: $period,
            inflow: Money::ofMinor($inflowMinor, $displayCurrency),
            outflow: Money::ofMinor($outflowMinor, $displayCurrency),
            net: Money::ofMinor($netMinor, $displayCurrency),
            topCategories: $this->topCategoriesQuery->for($user, $period, displayCurrency: $displayCurrency, limit: 5),
            recentTransactions: $recent->rows,
            uncategorizedCount: $uncategorized,
            isFirstRun: false,
        );
    }

    /**
     * Original-currency mode for the dashboard KPI tiles. Returns one tile-
     * row per distinct `settled_currency` present in the period with non-
     * zero activity (either inflow or outflow). Rows are ordered
     * alphabetically by ISO currency code so the rendered tile stack is
     * deterministic across renders. Zero-activity currencies — those whose
     * inflow and outflow both sum to zero in the period — are omitted by
     * the HAVING clause so the UI never paints empty rows.
     *
     * A period with only EUR-native activity collapses to a single tile-
     * row that visually matches the EUR-only mode output. Mixed-currency
     * periods (e.g. EUR + USD) return one tile-row per currency in
     * alphabetical order — EUR first, USD second.
     *
     * Same user-scoping guard as `for()` — every row is filtered by
     * `user_id` before grouping so the aggregate cannot leak across user
     * boundaries.
     *
     * @return list<PerCurrencyTile>
     */
    public function forByCurrency(User $user, Period $period): array
    {
        $connection = $this->db->connection();

        // Per-currency tiles apply the SAME type filter as `for()` so
        // the original-currency mode never silently double-counts
        // internal transfers as income / expense in any currency band.
        // Symmetric contract with the EUR-only rollup above.
        $rows = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->groupBy('settled_currency')
            ->havingRaw(
                "(COALESCE(SUM(CASE WHEN type = 'income' THEN settled_amount_minor ELSE 0 END), 0) <> 0)
                 OR (COALESCE(SUM(CASE WHEN type = 'expense' THEN -settled_amount_minor ELSE 0 END), 0) <> 0)"
            )
            ->selectRaw(
                "settled_currency,
                 COALESCE(SUM(CASE WHEN type = 'income' THEN settled_amount_minor ELSE 0 END), 0) AS inflow_minor,
                 COALESCE(SUM(CASE WHEN type = 'expense' THEN -settled_amount_minor ELSE 0 END), 0) AS outflow_minor,
                 COALESCE(SUM(CASE WHEN type IN ('income', 'expense') THEN settled_amount_minor ELSE 0 END), 0) AS net_minor"
            )
            ->orderBy('settled_currency')
            ->get();

        return array_values($rows->map(static function (stdClass $row): PerCurrencyTile {
            $currency = self::toString($row->settled_currency);

            return new PerCurrencyTile(
                currency: $currency,
                inflow: Money::ofMinor(self::toInt($row->inflow_minor), $currency),
                outflow: Money::ofMinor(self::toInt($row->outflow_minor), $currency),
                net: Money::ofMinor(self::toInt($row->net_minor), $currency),
            );
        })->all());
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

    /**
     * Coerces a raw query-builder scalar into a string. The grouped column
     * (`settled_currency`) always returns a string in the SQLite driver,
     * but the guard keeps PHPStan's strict-rules `cast.string` rule happy
     * without leaking `mixed` into the Money construction.
     */
    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (string) (is_scalar($value) ? $value : '');
    }
}
