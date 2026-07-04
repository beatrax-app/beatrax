<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Chains\Public\Dto\CardStatementForecastTile;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Public\Dto\EmailScanHealthTile;
use Modules\EmailScan\Public\Dto\InboxHealthLine;
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

    /**
     * Threshold (in seconds) beyond which an inbox is considered "stale"
     * on the dashboard tile — a successfully-scanned inbox that hasn't
     * been touched in over 24 hours surfaces an amber dot. Mirrors the
     * UI-SPEC threshold so tile colouring is consistent across renders.
     */
    private const STALE_THRESHOLD_SECONDS = 86400;

    /**
     * Maximum number of inbox lines surfaced inside the dashboard's
     * Email-scan-health tile. Extra inboxes collapse into the
     * "+{overflow} more" footer line.
     */
    private const TILE_LINE_LIMIT = 3;

    /**
     * Email local-part truncation length used when multiple inboxes
     * share a provider — keeps the per-line copy compact for the
     * three-line tile layout.
     */
    private const EMAIL_LOCAL_PART_MAX = 12;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TopCategoriesByPeriodQuery $topCategoriesQuery,
        private readonly TransactionListQuery $listQuery,
        private readonly Clock $clock,
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
        //
        // The income half is delegated to incomeForPeriod() — the one
        // canonical "subtractive income, transfers excluded" definition
        // (Phase 13.2 D-12 / Pitfall 3) — so this method no longer carries
        // its own copy of the `type = 'income'` CASE-WHEN.
        $inflowMinor = $this->incomeForPeriod($user, $period, $displayCurrency);

        $row = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('settled_currency', $displayCurrency)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type = 'expense' THEN -settled_amount_minor ELSE 0 END), 0) AS outflow_minor,
                 COALESCE(SUM(CASE WHEN type IN ('income', 'expense') THEN settled_amount_minor ELSE 0 END), 0) AS net_minor"
            )
            ->first();

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
     * The ONE canonical "subtractive income" sum for a (user, period,
     * currency): `SUM(settled_amount_minor)` over rows whose `type =
     * 'income'`, scoped to the period window and settled currency. A
     * `transfer_in` row (positive amount, but an internal move between own
     * accounts) contributes 0 — it is never `type = 'income'`. A period with
     * no income rows returns 0 (Laravel's query builder `sum()` returns 0,
     * never null, when no rows match).
     *
     * Extracted from `for()`'s inline CASE-WHEN (Phase 13.2 D-12 / Pitfall 3)
     * so exactly one definition of "income, transfers excluded" exists in
     * the codebase — `for()` calls this method internally, and
     * `CarryoverQuery` (Modules\Budgets) reuses it as its income source. Do
     * NOT add a second `WHERE type = 'income'` anywhere else; extend this
     * method if the rule ever needs to change.
     */
    public function incomeForPeriod(User $user, Period $period, string $currency = self::DEFAULT_DISPLAY_CURRENCY): int
    {
        $value = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('settled_currency', $currency)
            ->where('type', 'income')
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->sum('settled_amount_minor');

        return self::toInt($value);
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
     * Dashboard "Next ICS settlement" tile (D-99 / D-100, CHN-06).
     *
     * Returns the most-recent `open` or `partially_settled`
     * `card_statements` row joined to an `ics_card` account, mapped
     * to a `CardStatementForecastTile` DTO:
     *
     *   - amount   = open_balance_minor (D-100; the open balance IS
     *                the forecast — no clever cadence inference in
     *                Phase 5)
     *   - dueDate  = period_end + 5 calendar days (D-100; constant
     *                forecast lag; user-configurable lag deferred)
     *
     * Returns null when no open / partially_settled statement exists,
     * which the dashboard Blade reads as "hide the tile entirely"
     * (D-99 — no "—" placeholder).
     *
     * Cross-user safety: the WHERE filters on
     * `card_statements.user_id = $user->id` BEFORE any account join,
     * so a forged user_id cannot leak another user's statement.
     */
    public function nextIcsSettlement(User $user): ?CardStatementForecastTile
    {
        $row = $this->db->connection()
            ->table('card_statements')
            ->join('accounts', 'accounts.id', '=', 'card_statements.account_id')
            ->where('card_statements.user_id', $user->id)
            ->where('accounts.kind', 'ics_card')
            ->whereIn('card_statements.state', ['open', 'partially_settled'])
            ->orderByDesc('card_statements.period_end')
            ->orderByDesc('card_statements.id')
            ->select(
                'card_statements.id as id',
                'card_statements.open_balance_minor as open_balance_minor',
                'card_statements.period_end as period_end',
                'card_statements.state as state',
            )
            ->first();

        if ($row === null) {
            return null;
        }

        $periodEnd = CarbonImmutable::parse(self::toString($row->period_end));
        $openBalanceMinor = self::toInt($row->open_balance_minor);

        return new CardStatementForecastTile(
            amount: Money::ofMinor($openBalanceMinor, 'EUR'),
            dueDate: $periodEnd->addDays(5)->startOfDay(),
            statementId: self::toInt($row->id),
            state: self::toString($row->state),
        );
    }

    /**
     * Dashboard "Email scan health" tile.
     *
     * Returns up to three connected-inbox lines (in created_at order)
     * plus a tile-level overall status:
     *
     *   - 'reauth'  when ANY inbox has status='needs_reauth'
     *   - 'stale'   when no inbox needs reauth but at least one inbox
     *               has not been successfully scanned within the last
     *               24 hours (or has never been scanned)
     *   - 'healthy' otherwise
     *
     * Returns null when zero inboxes are connected — the dashboard
     * Blade reads this as "hide the tile entirely" so the surface
     * stays empty until the user connects an email account on
     * `/inboxes`. The DTO has no "no inboxes" sentinel; absence
     * becomes "no tile" upstream.
     *
     * Cross-user safety: the WHERE filter on `inboxes.user_id` is the
     * single user-scoping guard. The LEFT JOIN on `inbox_scan_state`
     * preserves rows whose scan-state has not been inserted yet (a
     * transient window after the OAuth callback lands but before the
     * background fetcher stamps the row); such rows render with
     * `lastScanAt = null` and the dashboard partial copies the
     * "not scanned yet" variant.
     */
    public function emailScanHealth(User $user): ?EmailScanHealthTile
    {
        $rows = $this->db->connection()
            ->table('inboxes')
            ->leftJoin('inbox_scan_state', static function ($join): void {
                /** @var JoinClause $join */
                $join->on('inbox_scan_state.inbox_id', '=', 'inboxes.id')
                    ->where('inbox_scan_state.folder', '=', 'INBOX');
            })
            ->where('inboxes.user_id', $user->id)
            ->orderBy('inboxes.created_at', 'asc')
            ->orderBy('inboxes.id', 'asc')
            ->select([
                'inboxes.id as id',
                'inboxes.provider as provider',
                'inboxes.email as email',
                'inbox_scan_state.status as status',
                'inbox_scan_state.last_scan_at as last_scan_at',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $nowEpoch = $this->clock->now()->getTimestamp();
        $overall = 'healthy';
        $lines = [];
        $emitted = 0;

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $rawStatus = self::toString($row->status ?? null);
            // LEFT JOIN miss means the scan-state row has not been
            // inserted yet — treat as idle so the UI reasoning matches
            // InboxQuery::makeDto() (Plan 03).
            $status = $rawStatus === '' ? 'idle' : $rawStatus;

            $lastScanRaw = self::toString($row->last_scan_at ?? null);
            $lastScanAt = null;
            if ($lastScanRaw !== '') {
                try {
                    $lastScanAt = new DateTimeImmutable($lastScanRaw);
                } catch (\Throwable) {
                    $lastScanAt = null;
                }
            }

            $lineStatus = 'healthy';
            if ($status === 'needs_reauth') {
                $lineStatus = 'reauth';
                $overall = 'reauth';
            } elseif ($lastScanAt === null) {
                $lineStatus = 'stale';
                if ($overall !== 'reauth') {
                    $overall = 'stale';
                }
            } elseif ($lastScanAt->getTimestamp() < ($nowEpoch - self::STALE_THRESHOLD_SECONDS)) {
                $lineStatus = 'stale';
                if ($overall !== 'reauth') {
                    $overall = 'stale';
                }
            }

            if ($emitted < self::TILE_LINE_LIMIT) {
                $email = self::toString($row->email);
                $atPos = strpos($email, '@');
                $localPart = $atPos === false ? $email : substr($email, 0, $atPos);
                $localPart = substr($localPart, 0, self::EMAIL_LOCAL_PART_MAX);

                $lines[] = new InboxHealthLine(
                    provider: self::toString($row->provider),
                    emailLocalPart: $localPart,
                    lastScanAt: $lastScanAt,
                    status: $lineStatus,
                );
                $emitted++;
            }
        }

        $overflowCount = max(0, $rows->count() - self::TILE_LINE_LIMIT);

        return new EmailScanHealthTile(
            lines: $lines,
            overallStatus: $overall,
            overflowCount: $overflowCount,
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
