<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use InvalidArgumentException;
use Modules\Chains\Public\Dto\CardStatementForecastTile;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\Duration;
use Modules\EmailScan\Public\Dto\EmailScanHealthTile;
use Modules\EmailScan\Public\Dto\InboxHealthLine;
use Modules\EmailScan\Public\Enums\InboxScanStatus;
use Modules\EmailScan\Public\Services\InboxScanSchedule;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Dto\DashboardSummary;
use Modules\Ledger\Public\Dto\PerCurrencyTile;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\TopCategories;
use Modules\Ledger\Public\Enums\MoneyFlow;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Support\SplitLegs;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

final readonly class ThisPeriodAtAGlanceQuery
{
    use CoercesScalars;

    // 86400 = 24h: where a scan is scheduled, an inbox untouched longer than
    // that shows an amber dot. Inboxes past TILE_LINE_LIMIT collapse into a
    // "+N more" line.

    private const int TILE_LINE_LIMIT = 3;

    private const int EMAIL_LOCAL_PART_MAX = 12;

    // Where a scan is scheduled, an inbox untouched for longer than this is
    // behind rather than merely quiet.
    private static function staleThresholdSeconds(): int
    {
        return Duration::Day->seconds();
    }

    public function __construct(
        private DatabaseManager $db,
        private TopCategoriesByPeriodQuery $topCategoriesQuery,
        private TransactionListQuery $listQuery,
        private Clock $clock,
        private BaseCurrency $baseCurrency,
        private CrossCurrencyTotal $fx,
        private CardStatementQuery $cardStatements,
    ) {}

    public function for(User $user, Period $period, ?string $displayCurrency = null): DashboardSummary
    {
        $displayCurrency ??= $this->baseCurrency->code();

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
                topCategories: TopCategories::none($displayCurrency),
                recentTransactions: [],
                uncategorizedCount: 0,
                isFirstRun: true,
            );
        }

        // Rollups filter by transactions.type, never by amount sign, and which
        // types each one counts is MoneyFlow's to say. Bucketed by the currency
        // each row settled in: an account denominated in anything else was
        // filtered away, reading as a period with no money in it.
        $buckets = $this->bucketsByCurrency($user, $period);

        $inflowByCurrency = [];
        $outflowByCurrency = [];
        foreach ($buckets as $bucket) {
            $currency = self::toString($bucket->settled_currency);
            $inflowByCurrency[$currency] = self::toInt($bucket->inflow_minor);
            $outflowByCurrency[$currency] = self::toInt($bucket->outflow_minor);
        }

        $rates = $this->fx->ratesTo(array_keys($inflowByCurrency), $displayCurrency);
        $inflow = $this->fx->withRates($inflowByCurrency, $displayCurrency, $rates);
        $outflow = $this->fx->withRates($outflowByCurrency, $displayCurrency, $rates);

        $uncategorized = SplitLegs::excludeParents(
            $connection->table('transactions')
                ->where('user_id', $user->id)
                ->whereNull('category_id')
        )->count();

        $recent = $this->listQuery->recent($user, daysBack: 90, limit: 10, currency: $displayCurrency);

        return new DashboardSummary(
            period: $period,
            inflow: Money::ofMinor($inflow->minor, $displayCurrency),
            outflow: Money::ofMinor($outflow->minor, $displayCurrency),
            // Subtracted after conversion, never converted itself: a separately
            // converted net can miss the two tiles above it by a cent.
            net: Money::ofMinor($inflow->minor - $outflow->minor, $displayCurrency),
            topCategories: $this->topCategoriesQuery->for($user, $period, displayCurrency: $displayCurrency, limit: 5),
            recentTransactions: $recent->rows,
            uncategorizedCount: $uncategorized,
            isFirstRun: false,
            unconvertedCurrencies: $inflow->unconverted,
        );
    }

    // The one canonical "subtractive income" sum. Do not add a second
    // WHERE type = 'income' anywhere else — extend this method if the
    // rule ever needs to change (see the linked architecture page).
    public function incomeForPeriod(User $user, Period $period, ?string $currency = null): int
    {
        $currency ??= $this->baseCurrency->code();

        $rows = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('type', TransactionType::Income->value)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->groupBy('settled_currency')
            ->selectRaw('settled_currency, COALESCE(SUM(settled_amount_minor), 0) AS income_minor')
            ->get();

        $byCurrency = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $byCurrency[self::toString($row->settled_currency)] = self::toInt($row->income_minor);
        }

        return $this->fx->of($byCurrency, $currency)->minor;
    }

    // Income over a whole span, grouped by day and left unconverted, so a
    // caller folding period by period pays one query and one rate lookup for
    // the walk rather than one of each per period.
    /**
     * @return array<string, array<string, int>> posted_at => currency => minor
     */
    public function incomeForSpanByCurrencyPerDay(User $user, Period $span): array
    {
        $rows = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('type', TransactionType::Income->value)
            ->where('posted_at', '>=', $span->start->toDateString())
            ->where('posted_at', '<', $span->endExclusive->toDateString())
            ->groupBy('posted_at', 'settled_currency')
            ->selectRaw('posted_at, settled_currency, COALESCE(SUM(settled_amount_minor), 0) AS income_minor')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $day = self::toString($row->posted_at);
            $byDay[$day][self::toString($row->settled_currency)] = self::toInt($row->income_minor);
        }

        return $byDay;
    }

    /**
     * @return list<stdClass>
     */
    private function bucketsByCurrency(User $user, Period $period): array
    {
        $inflowTypes = MoneyFlow::Income->types();
        $outflowTypes = MoneyFlow::Spend->types();
        $netTypes = MoneyFlow::Net->types();

        $inflowSum = 'COALESCE(SUM(CASE WHEN type IN ('.self::binds($inflowTypes).') THEN settled_amount_minor ELSE 0 END), 0)';
        $outflowSum = 'COALESCE(SUM(CASE WHEN type IN ('.self::binds($outflowTypes).') THEN -settled_amount_minor ELSE 0 END), 0)';
        $netSum = 'COALESCE(SUM(CASE WHEN type IN ('.self::binds($netTypes).') THEN settled_amount_minor ELSE 0 END), 0)';

        $rows = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->groupBy('settled_currency')
            ->havingRaw(
                '('.$inflowSum.' <> 0) OR ('.$outflowSum.' <> 0)',
                [...$inflowTypes, ...$outflowTypes],
            )
            ->selectRaw(
                'settled_currency, '.$inflowSum.' AS inflow_minor, '.$outflowSum.' AS outflow_minor, '.$netSum.' AS net_minor',
                [...$inflowTypes, ...$outflowTypes, ...$netTypes],
            )
            ->orderBy('settled_currency')
            ->get();

        /** @var list<stdClass> $all */
        $all = $rows->all();

        return $all;
    }

    /**
     * @param  list<string>  $types
     */
    // havingRaw()/selectRaw() need a literal-string, so the placeholder run is
    // matched against a fixed set rather than built with implode(). The counts
    // are bounded by TransactionType's cases; anything else is a caller bug.
    /**
     * @param  list<string>  $types
     * @return literal-string
     */
    private static function binds(array $types): string
    {
        return match (count($types)) {
            1 => '?',
            2 => '?, ?',
            3 => '?, ?, ?',
            4 => '?, ?, ?, ?',
            5 => '?, ?, ?, ?, ?',
            6 => '?, ?, ?, ?, ?, ?',
            7 => '?, ?, ?, ?, ?, ?, ?',
            default => throw new InvalidArgumentException('Unsupported bind count: '.count($types)),
        };
    }

    /**
     * @return list<PerCurrencyTile>
     */
    public function forByCurrency(User $user, Period $period): array
    {
        // Per-currency tiles apply the same type filter as for() so
        // original-currency mode never double-counts internal transfers.
        return array_map(static function (stdClass $row): PerCurrencyTile {
            $currency = self::toString($row->settled_currency);

            return new PerCurrencyTile(
                currency: $currency,
                inflow: Money::ofMinor(self::toInt($row->inflow_minor), $currency),
                outflow: Money::ofMinor(self::toInt($row->outflow_minor), $currency),
                net: Money::ofMinor(self::toInt($row->net_minor), $currency),
            );
        }, $this->bucketsByCurrency($user, $period));
    }

    // Chains owns card_statements and the reason the raw open balance is not
    // what will be paid, so the read is handed there rather than repeated. Read
    // here it deducted no credits, and one statement was two amounts.
    public function nextIcsSettlement(User $user): ?CardStatementForecastTile
    {
        return $this->cardStatements->forecastTileForUser($user);
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
        $scanIsScheduledHere = InboxScanSchedule::runsOnThisDevice();
        $overall = 'healthy';
        $lines = [];
        $emitted = 0;

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $rawStatus = self::toString($row->status ?? null);
            // LEFT JOIN miss means the scan-state row has not been
            // inserted yet — treat as idle to match InboxQuery::makeDto().
            $status = $rawStatus === '' ? 'idle' : $rawStatus;

            $lastScanAt = self::parseLastScanAt(self::toString($row->last_scan_at ?? null));
            $lineStatus = self::lineStatusFor($status, $lastScanAt, $nowEpoch, $scanIsScheduledHere);
            $overall = self::escalateOverall($overall, $lineStatus);

            if ($emitted < self::TILE_LINE_LIMIT) {
                $lines[] = $this->makeHealthLine($row, $lastScanAt, $lineStatus);
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

    // Null reads downstream as "never scanned", which the stale check treats
    // the same as long-ago.
    private static function parseLastScanAt(string $raw): ?DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    // Stale means a schedule fell behind, so only a device that runs one can
    // reach it: where no scan is scheduled, never-scanned and long-ago are
    // both the arrangement working as designed, and say so instead.
    private static function lineStatusFor(string $status, ?DateTimeImmutable $lastScanAt, int $nowEpoch, bool $scanIsScheduledHere): string
    {
        if ($status === InboxScanStatus::NeedsReauth->value) {
            return 'reauth';
        }

        if ($lastScanAt !== null && $lastScanAt->getTimestamp() >= ($nowEpoch - self::staleThresholdSeconds())) {
            return 'healthy';
        }

        return $scanIsScheduledHere ? 'stale' : 'unscheduled';
    }

    // reauth outranks stale outranks unscheduled outranks healthy, and a later
    // row never downgrades a rank already reached. unscheduled sits above
    // healthy because an unscanned inbox is not one the tile can vouch for,
    // and below stale because nothing here was late.
    private static function escalateOverall(string $current, string $lineStatus): string
    {
        return match (true) {
            $current === 'reauth' || $lineStatus === 'reauth' => 'reauth',
            $current === 'stale' || $lineStatus === 'stale' => 'stale',
            $current === 'unscheduled' || $lineStatus === 'unscheduled' => 'unscheduled',
            default => $current,
        };
    }

    private function makeHealthLine(stdClass $row, ?DateTimeImmutable $lastScanAt, string $lineStatus): InboxHealthLine
    {
        $email = self::toString($row->email);
        $atPos = strpos($email, '@');
        $localPart = $atPos === false ? $email : substr($email, 0, $atPos);
        $localPart = substr($localPart, 0, self::EMAIL_LOCAL_PART_MAX);

        return new InboxHealthLine(
            provider: self::toString($row->provider),
            emailLocalPart: $localPart,
            lastScanAt: $lastScanAt,
            status: $lineStatus,
        );
    }
}
