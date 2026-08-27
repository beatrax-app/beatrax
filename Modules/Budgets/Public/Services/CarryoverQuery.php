<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Budgets\Internal\Fold\FoldContext;
use Modules\Budgets\Internal\Fold\FoldStep;
use Modules\Budgets\Public\Dto\EnvelopeRow;
use Modules\Budgets\Public\Enums\OverspendMode;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\SafeDate;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\SpendByCategoryQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Ledger\Public\ValueObjects\Money;
use Psr\Log\LoggerInterface;
use stdClass;

final class CarryoverQuery
{
    use CoercesScalars;

    private const DEFAULT_OVERSPEND_MODE = OverspendMode::ReduceToBudget->value;

    // Fallback when envelope_settings.threshold_percent is null. Resolved here
    // so the nudge job reads EnvelopeRow::$notifyThresholdPercent, never the default.
    public const DEFAULT_NOTIFY_THRESHOLD_PERCENT = 90;

    private const FUTURE_HORIZON_PERIODS = 12;

    private const MAX_WALK_PERIODS = 1000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PeriodQuery $periods,
        private readonly SpendByCategoryQuery $spendByCategory,
        private readonly ThisPeriodAtAGlanceQuery $glance,
        private readonly BudgetProgressQuery $budgetProgress,
        private readonly Clock $clock,
        private readonly LoggerInterface $log,
        private readonly BaseCurrency $baseCurrency,
        private readonly CrossCurrencyTotal $fx,
    ) {}

    // Nothing is budgeted, carried or moved before the first assignment — but
    // there IS spending, and reporting it as zero told a reader looking at a
    // month of transactions that they had spent nothing. Assigned/carried/moved
    // are genuinely nought, so the fold's arithmetic collapses to -spent.
    /**
     * @return array{overspentCount: int, rows: array<int, EnvelopeRow>}
     */
    private function unstartedRows(User $user, Period $target): array
    {
        $settings = $this->envelopeSettings($user);
        $currency = $this->baseCurrency->code();
        $spendByCategory = $this->batchSpend($user, [$target], $target)[$target->start->toDateString()] ?? [];

        $rows = [];
        $overspentCount = 0;

        foreach ($this->budgetProgress->expenseCategoryNaming($user) as $categoryId => $naming) {
            $spent = $spendByCategory[$categoryId]['spent'] ?? 0;
            $available = -$spent;

            if ($available < 0) {
                $overspentCount++;
            }

            $rows[$categoryId] = new EnvelopeRow(
                categoryId: $categoryId,
                categoryName: $naming['name'],
                assignedMinor: 0,
                spentMinor: $spent,
                carriedInMinor: 0,
                netMovedMinor: 0,
                availableMinor: $available,
                overspendMode: $settings['modes'][$categoryId] ?? self::DEFAULT_OVERSPEND_MODE,
                currency: $currency,
                unconvertedSpentMinor: $spendByCategory[$categoryId]['unconverted'] ?? 0,
                notifyThresholdPercent: $settings['thresholds'][$categoryId] ?? self::DEFAULT_NOTIFY_THRESHOLD_PERCENT,
                categorySlug: $naming['slug'],
                categoryNameIsDefault: $naming['isDefault'],
            );
        }

        return ['overspentCount' => $overspentCount, 'rows' => $rows];
    }

    /**
     * @return array{toBudgetMinor: int, overspentCount: int, rows: array<int, EnvelopeRow>}
     */
    public function forUserAndPeriod(User $user, Period $target): array
    {
        $genesisPeriod = $this->genesisPeriodFor($user);

        if ($genesisPeriod === null) {
            // Pre-genesis the fold is income + carry - assigned, and carry and
            // assigned are both nought, so income is what it would answer. Zero
            // told a reader with a month's pay banked they had nothing to assign;
            // [] rendered "you have no expense categories" at 24 of them.
            $unstarted = $this->unstartedRows($user, $target);

            return [
                'toBudgetMinor' => $this->glance->incomeForPeriod($user, $target, $this->baseCurrency->code()),
                'overspentCount' => $unstarted['overspentCount'],
                'rows' => $unstarted['rows'],
            ];
        }

        $currentPeriod = $this->periods->containing($this->clock->now());
        $maxPeriod = $currentPeriod;
        for ($i = 0; $i < self::FUTURE_HORIZON_PERIODS; $i++) {
            $maxPeriod = $this->periods->next($maxPeriod);
        }

        $targetBounded = $target;
        if ($targetBounded->start->greaterThan($maxPeriod->start)) {
            $targetBounded = $maxPeriod;
        }
        if ($targetBounded->start->lessThan($genesisPeriod->start)) {
            $targetBounded = $genesisPeriod;
        }

        $periodsWalk = $this->walkPeriods($genesisPeriod, $targetBounded);

        $expenseCategories = $this->budgetProgress->expenseCategoryNaming($user);

        $assignedByPeriod = $this->batchAssignments($user, $genesisPeriod, $targetBounded);
        $movedByPeriod = $this->batchMoves($user, $genesisPeriod, $targetBounded);
        $settings = $this->envelopeSettings($user);
        $overspendModeByCategory = $settings['modes'];
        $notifyThresholdByCategory = $settings['thresholds'];

        $poolCarry = 0;
        $carriedIn = [];
        foreach (array_keys($expenseCategories) as $categoryId) {
            $carriedIn[$categoryId] = 0;
        }

        $context = new FoldContext($expenseCategories, $overspendModeByCategory, $notifyThresholdByCategory);
        $result = null;

        // The walk starts at genesis, so reading spend, income and FX per
        // period cost a round trip per month of the reader's whole history.
        $span = new Period($periodsWalk[0]->start, end($periodsWalk)->endExclusive, '');
        $spendByPeriod = $this->batchSpend($user, $periodsWalk, $span);
        $incomeByPeriod = $this->batchIncome($user, $periodsWalk, $span);

        foreach ($periodsWalk as $period) {
            $periodKey = $period->start->toDateString();
            $step = $this->foldPeriod(
                $user,
                $period,
                $context,
                $assignedByPeriod[$periodKey] ?? [],
                $movedByPeriod[$periodKey] ?? [],
                $poolCarry,
                $carriedIn,
                $spendByPeriod[$periodKey] ?? [],
                $incomeByPeriod[$periodKey] ?? 0,
            );

            $poolCarry = $step->poolCarry;
            $carriedIn = $step->carriedIn;

            if ($period->start->equalTo($targetBounded->start)) {
                $result = [
                    'toBudgetMinor' => $step->toBudgetMinor,
                    'overspentCount' => $step->overspentCount,
                    'rows' => $step->rows,
                ];
            }
        }

        if ($result === null) {
            // Only reachable if MAX_WALK_PERIODS tripped first; target is bounded
            // to current+12. A degraded read beats a fatal budgets page.
            $this->log->warning('CarryoverQuery fold hit the walk cap before reaching the target period; returning all-zero.', [
                'user_id' => $user->id,
                'genesis' => $genesisPeriod->start->toDateString(),
                'target' => $targetBounded->start->toDateString(),
                'max_walk_periods' => self::MAX_WALK_PERIODS,
            ]);

            return ['toBudgetMinor' => 0, 'overspentCount' => 0, 'rows' => []];
        }

        return $result;
    }

    // The one anchor: the fold clamps back-navigation to it and the budgets page
    // gates its month-back control on it, so a second derivation would let the
    // two disagree about which months exist.
    public function genesisPeriodFor(User $user): ?Period
    {
        $anchor = $this->genesisAnchorFor($user);

        return $anchor === null ? null : $this->periods->containing($anchor);
    }

    // Query builder, not the User model: a Public service must not depend on
    // Core\Models\User carrying a cast for a column this module's migration owns.
    private function genesisAnchorFor(User $user): ?CarbonImmutable
    {
        $raw = $this->db->connection()
            ->table('users')
            ->where('id', $user->id)
            ->value('envelope_activated_at');

        return SafeDate::parseOrNull(self::toString($raw)) ?? $this->earliestAssignedPeriodFor($user);
    }

    // envelope_activated_at is absent from the merge registry, so a device that
    // joined by pairing has it null forever and reported every synced assignment
    // as zero. The earliest assigned month is the honest anchor.
    private function earliestAssignedPeriodFor(User $user): ?CarbonImmutable
    {
        $earliest = $this->db->connection()
            ->table('envelope_assignments')
            ->where('user_id', $user->id)
            ->min('period_start');

        return is_string($earliest) ? SafeDate::parseOrNull($earliest) : null;
    }

    /**
     * @param  array<int, int>  $assignedByCategory
     * @param  array<int, int>  $movedByCategory
     * @param  array<int, int>  $carriedIn
     * @param  array<int, array{spent: int, unconverted: int}>  $spendByCategory
     */
    private function foldPeriod(
        User $user,
        Period $period,
        FoldContext $context,
        array $assignedByCategory,
        array $movedByCategory,
        int $poolCarry,
        array $carriedIn,
        array $spendByCategory,
        int $income,
    ): FoldStep {
        $currency = $this->baseCurrency->code();

        $totalAssignedMoney = Money::ofMinor(0, $currency);
        foreach ($assignedByCategory as $assignedMinor) {
            $totalAssignedMoney = $totalAssignedMoney->plus(Money::ofMinor($assignedMinor, $currency));
        }

        $toBudgetMoney = Money::ofMinor($income, $currency)
            ->plus(Money::ofMinor($poolCarry, $currency))
            ->minus($totalAssignedMoney);

        $nextCarriedIn = [];
        $shortfallMoney = Money::ofMinor(0, $currency);
        $rows = [];
        $overspentCount = 0;

        foreach ($context->expenseCategories as $categoryId => $naming) {
            $assigned = $assignedByCategory[$categoryId] ?? 0;
            $moved = $movedByCategory[$categoryId] ?? 0;
            $spent = $spendByCategory[$categoryId]['spent'] ?? 0;
            $carriedInForCategory = $carriedIn[$categoryId] ?? 0;
            $unconvertedSpent = $spendByCategory[$categoryId]['unconverted'] ?? 0;

            $availableMoney = Money::ofMinor($assigned, $currency)
                ->plus(Money::ofMinor($carriedInForCategory, $currency))
                ->plus(Money::ofMinor($moved, $currency))
                ->minus(Money::ofMinor($spent, $currency));
            $available = $availableMoney->toMinor();

            $mode = $context->overspendModeByCategory[$categoryId] ?? self::DEFAULT_OVERSPEND_MODE;
            $notifyThreshold = $context->notifyThresholdByCategory[$categoryId] ?? self::DEFAULT_NOTIFY_THRESHOLD_PERCENT;

            // The overspend modes differ in who absorbs a negative envelope:
            // reduce_to_budget hands the shortfall to the shared pool once per
            // period, carry_negative leaves it in the envelope and off the pool.
            if ($available < 0 && $mode === self::DEFAULT_OVERSPEND_MODE) {
                $shortfallMoney = $shortfallMoney->plus($availableMoney);
                $nextCarriedIn[$categoryId] = 0;
            } else {
                $nextCarriedIn[$categoryId] = $available;
            }

            if ($available < 0) {
                $overspentCount++;
            }

            $rows[$categoryId] = new EnvelopeRow(
                categoryId: $categoryId,
                categoryName: $naming['name'],
                assignedMinor: $assigned,
                spentMinor: $spent,
                carriedInMinor: $carriedInForCategory,
                netMovedMinor: $moved,
                availableMinor: $available,
                overspendMode: $mode,
                currency: $currency,
                unconvertedSpentMinor: $unconvertedSpent,
                notifyThresholdPercent: $notifyThreshold,
                categorySlug: $naming['slug'],
                categoryNameIsDefault: $naming['isDefault'],
            );
        }

        return new FoldStep(
            poolCarry: $toBudgetMoney->plus($shortfallMoney)->toMinor(),
            carriedIn: $nextCarriedIn,
            toBudgetMinor: $toBudgetMoney->toMinor(),
            overspentCount: $overspentCount,
            rows: $rows,
        );
    }

    /**
     * @param  array<array-key, mixed>  $spentByKey
     */
    // Reuses the shared spend query: a fresh GROUP BY would double-count a split
    // transaction, already legs union unsplit parents. Buckets are converted
    // into the currency the fold runs in, and one the rate table cannot reach
    // stays out of it, surfaced beside the row rather than counted at par.
    /**
     * @return array<int, array{spent: int, unconverted: int}>
     */
    /**
     * @param  list<Period>  $periodsWalk
     * @return array<string, array<int, array{spent: int, unconverted: int}>>
     */
    private function batchSpend(User $user, array $periodsWalk, Period $span): array
    {
        $currency = $this->baseCurrency->code();
        $byDay = $this->spendByCategory->forUserAndSpanByCurrencyPerDay($user->id, $span);

        $bucketsByPeriod = [];
        $currencies = [];
        foreach ($periodsWalk as $period) {
            $periodKey = $period->start->toDateString();
            $bucketsByPeriod[$periodKey] = [];

            foreach ($byDay as $day => $keys) {
                if ($day < $periodKey || $day >= $period->endExclusive->toDateString()) {
                    continue;
                }

                foreach ($keys as $key => $minor) {
                    [$categoryId, $bucketCurrency] = explode('|', self::toString($key), 2) + [1 => ''];
                    $bucketsByPeriod[$periodKey][(int) $categoryId][$bucketCurrency]
                        = ($bucketsByPeriod[$periodKey][(int) $categoryId][$bucketCurrency] ?? 0) + $minor;
                    $currencies[] = $bucketCurrency;
                }
            }
        }

        // One rate lookup for the walk: the base currency never changes across
        // it, so asking per period was the same answer fetched N times.
        $rates = $this->fx->ratesTo($currencies, $currency);

        $spendByPeriod = [];
        foreach ($bucketsByPeriod as $periodKey => $buckets) {
            $spend = [];
            foreach ($buckets as $categoryId => $byCurrency) {
                $converted = $this->fx->withRates($byCurrency, $currency, $rates);

                $unreached = 0;
                foreach ($converted->unconverted as $code) {
                    $unreached += $byCurrency[$code] ?? 0;
                }

                $spend[$categoryId] = ['spent' => $converted->minor, 'unconverted' => $unreached];
            }
            $spendByPeriod[$periodKey] = $spend;
        }

        return $spendByPeriod;
    }

    /**
     * @param  list<Period>  $periodsWalk
     * @return array<string, int>
     */
    private function batchIncome(User $user, array $periodsWalk, Period $span): array
    {
        $currency = $this->baseCurrency->code();
        $byDay = $this->glance->incomeForSpanByCurrencyPerDay($user, $span);

        $byPeriod = [];
        $currencies = [];
        foreach ($periodsWalk as $period) {
            $periodKey = $period->start->toDateString();
            $totals = [];

            foreach ($byDay as $day => $perCurrency) {
                if ($day < $periodKey || $day >= $period->endExclusive->toDateString()) {
                    continue;
                }

                foreach ($perCurrency as $code => $minor) {
                    $totals[$code] = ($totals[$code] ?? 0) + $minor;
                    $currencies[] = $code;
                }
            }

            $byPeriod[$periodKey] = $totals;
        }

        $rates = $this->fx->ratesTo($currencies, $currency);

        return array_map(
            fn (array $totals): int => $this->fx->withRates($totals, $currency, $rates)->minor,
            $byPeriod,
        );
    }

    /**
     * @return list<Period>
     */
    private function walkPeriods(Period $genesis, Period $target): array
    {
        $cursor = $genesis;
        $periods = [$cursor];
        $guard = 0;

        while ($cursor->start->lessThan($target->start) && $guard < self::MAX_WALK_PERIODS) {
            $cursor = $this->periods->next($cursor);
            $periods[] = $cursor;
            $guard++;
        }

        return $periods;
    }

    /**
     * @return array<string, array<int, int>> "Y-m-d" period_start => category_id => assigned_minor
     */
    private function batchAssignments(User $user, Period $genesis, Period $target): array
    {
        $rows = $this->db->connection()
            ->table('envelope_assignments')
            ->where('user_id', $user->id)
            ->where('period_start', '>=', $genesis->start->toDateString())
            ->where('period_start', '<', $target->start->addDay()->toDateString())
            ->get(['category_id', 'period_start', 'assigned_minor', 'currency']);

        return $this->convertedByPeriod($rows, 'assigned_minor');
    }

    /**
     * @return array<string, array<int, int>> "Y-m-d" period_start => category_id => net_moved_minor
     */
    private function batchMoves(User $user, Period $genesis, Period $target): array
    {
        $connection = $this->db->connection();

        $rows = $connection
            ->table('envelope_moves')
            ->where('user_id', $user->id)
            ->where('period_start', '>=', $genesis->start->toDateString())
            ->where('period_start', '<', $target->start->addDay()->toDateString())
            ->groupBy('period_start', 'category_id', 'currency')
            ->get(['period_start', 'category_id', 'currency', $connection->raw('SUM(amount_minor) AS net_minor')]);

        return $this->convertedByPeriod($rows, 'net_minor');
    }

    // A row records the currency it was written in, and the reader's reporting
    // currency can have changed since. Converted per bucket on the way into the
    // fold, which then runs on figures actually denominated in what it prints.
    /**
     * @param  iterable<stdClass>  $rows
     * @return array<string, array<int, int>>
     */
    private function convertedByPeriod(iterable $rows, string $minorColumn): array
    {
        $currency = $this->baseCurrency->code();

        $buckets = [];
        $currencies = [];
        foreach ($rows as $row) {
            $fields = (array) $row;
            $periodKey = self::periodKeyFromRaw($fields['period_start'] ?? null);
            $categoryId = self::toInt($fields['category_id'] ?? null);
            $rowCurrency = self::toString($fields['currency'] ?? null);
            $currencies[] = $rowCurrency;
            $buckets[$periodKey][$categoryId][$rowCurrency] =
                ($buckets[$periodKey][$categoryId][$rowCurrency] ?? 0) + self::toInt($fields[$minorColumn] ?? null);
        }

        $rates = $this->fx->ratesTo($currencies, $currency);

        $byPeriod = [];
        foreach ($buckets as $periodKey => $byCategory) {
            foreach ($byCategory as $categoryId => $byCurrency) {
                $byPeriod[$periodKey][$categoryId] = $this->fx->withRates($byCurrency, $currency, $rates)->minor;
            }
        }

        return $byPeriod;
    }

    /**
     * @return array{modes: array<int, string>, thresholds: array<int, int>}
     */
    private function envelopeSettings(User $user): array
    {
        $rows = $this->db->connection()
            ->table('envelope_settings')
            ->where('user_id', $user->id)
            ->get(['category_id', 'overspend_mode', 'threshold_percent']);

        $modes = [];
        $thresholds = [];
        foreach ($rows as $row) {
            $categoryId = self::toInt($row->category_id);
            $modes[$categoryId] = self::toString($row->overspend_mode);
            $thresholds[$categoryId] = is_numeric($row->threshold_percent ?? null)
                ? (int) $row->threshold_percent
                : self::DEFAULT_NOTIFY_THRESHOLD_PERCENT;
        }

        return ['modes' => $modes, 'thresholds' => $thresholds];
    }

    private static function periodKeyFromRaw(mixed $value): string
    {
        $raw = self::toString($value);

        return SafeDate::parseOrNull($raw)?->toDateString() ?? $raw;
    }
}
