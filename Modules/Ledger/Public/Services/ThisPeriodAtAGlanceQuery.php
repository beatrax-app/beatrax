<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Chains\Public\Dto\CardStatementForecastTile;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Public\Dto\EmailScanHealthTile;
use Modules\EmailScan\Public\Dto\InboxHealthLine;
use Modules\Ledger\Public\Dto\DashboardSummary;
use Modules\Ledger\Public\Dto\PerCurrencyTile;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
final class ThisPeriodAtAGlanceQuery
{
    use CoercesScalars;

    public const DEFAULT_DISPLAY_CURRENCY = 'EUR';

    // A successfully-scanned inbox untouched for over 24h surfaces an
    // amber dot on the dashboard tile. Extra inboxes beyond
    // TILE_LINE_LIMIT collapse into a "+N more" footer line, and
    // EMAIL_LOCAL_PART_MAX keeps per-line copy compact for that layout.
    private const STALE_THRESHOLD_SECONDS = 86400;

    private const TILE_LINE_LIMIT = 3;

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

        // Inflow/outflow rollups filter by transactions.type, never by
        // amount sign (the subtractive income rule — see the linked
        // architecture page). The income half delegates to
        // incomeForPeriod(), the one canonical definition of this rule.
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

        // Keeps the "Recent transactions" panel consistent with the
        // currency-scoped tiles — a EUR view never surfaces USD/JPY rows.
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

    // The one canonical "subtractive income" sum. Do not add a second
    // WHERE type = 'income' anywhere else — extend this method if the
    // rule ever needs to change (see the linked architecture page).
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
     * @return list<PerCurrencyTile>
     */
    public function forByCurrency(User $user, Period $period): array
    {
        $connection = $this->db->connection();

        // Per-currency tiles apply the same type filter as for() so
        // original-currency mode never double-counts internal transfers.
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

    // Returns null when no open/partially_settled statement exists,
    // which the Blade reads as "hide the tile entirely". The WHERE
    // filters on card_statements.user_id before any account join, so a
    // forged user_id cannot leak another user's statement.
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

    // Returns null when zero inboxes are connected — see the linked
    // architecture page for the overall-status rule and the
    // LEFT JOIN's transient-row handling.
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
            // inserted yet — treat as idle to match InboxQuery::makeDto().
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
            } elseif ($lastScanAt === null || $lastScanAt->getTimestamp() < ($nowEpoch - self::STALE_THRESHOLD_SECONDS)) {
                // Never scanned and scanned too long ago are the same thing
                // to a reader of this tile: the figure cannot be trusted.
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
}
