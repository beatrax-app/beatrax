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

final readonly class CarryoverQuery
{
    use CoercesScalars;

    private const OverspendMode DEFAULT_OVERSPEND_MODE = OverspendMode::ReduceToBudget;

    // Fallback when envelope_settings.threshold_percent is null. Resolved here
    // so the nudge job reads EnvelopeRow::$notifyThresholdPercent, never the default.
    public const int DEFAULT_NOTIFY_THRESHOLD_PERCENT = 90;

    // Public because BudgetsPage bounds its month-forward control on the same
    // number: nav past the fold's forward walk limit reads an unfolded period.
    public const int FUTURE_HORIZON_PERIODS = 12;

    private const int MAX_WALK_PERIODS = 1000;

    public function __construct(
        private DatabaseManager $db,
        private PeriodQuery $periods,
        private SpendByCategoryQuery $spendByCategory,
        private ThisPeriodAtAGlanceQuery $glance,
        private BudgetProgressQuery $budgetProgress,
        private Clock $clock,
        private LoggerInterface $log,
        private BaseCurrency $baseCurrency,
        private CrossCurrencyTotal $fx,
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
                overspendMode: OverspendMode::tryFrom($settings['modes'][$categoryId] ?? '') ?? self::DEFAULT_OVERSPEND_MODE,
                currency: $currency,
                unconvertedSpentMinor: $spendByCategory[$categoryId]['unconverted'] ?? 0,
                notifyThresholdPercent: $settings['thresholds'][$categoryId] ?? self::DEFAULT_NOTIFY_THRESHOLD_PERCENT,
                categorySlug: $naming['slug'],
                categoryNameIsDefault: $naming['isDefault'],
                categoryPath: $naming['path'],
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

        $targetBounded = $this->bound($genesisPeriod, $target);

        $periodsWalk = $this->walkPeriods($user, $genesisPeriod, $targetBounded);

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

        // The walk can start at genesis, so reading spend, income and FX per
        // period cost a round trip per month of the reader's whole history.
        $span = new Period($periodsWalk[0]->start, end($periodsWalk)->endExclusive, '');
        $spendByPeriod = $this->batchSpend($user, $periodsWalk, $span);
        $incomeByPeriod = $this->batchIncome($user, $periodsWalk, $span);

        foreach ($periodsWalk as $period) {
            $periodKey = $period->start->toDateString();
            $step = $this->foldPeriod(
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
            // walkPeriods() ends on the target, so this is an invariant break
            // rather than any input the reader can hold. A degraded read beats
            // a fatal budgets page.
            $this->log->warning('CarryoverQuery fold walked a range not containing its own target period; returning all-zero.', [
                'user_id' => $user->id,
                'genesis' => $genesisPeriod->start->toDateString(),
                'target' => $targetBounded->start->toDateString(),
                'walked_from' => $periodsWalk[0]->start->toDateString(),
            ]);

            return ['toBudgetMinor' => 0, 'overspentCount' => 0, 'rows' => []];
        }

        return $result;
    }

    // The month the fold will answer for, which is not always the one it was
    // asked for. BudgetsPage renders THIS period rather than the anchor it
    // resolved, or the heading names one month while the grid draws another.
    public function boundedPeriodFor(User $user, Period $target): Period
    {
        $genesisPeriod = $this->genesisPeriodFor($user);

        return $genesisPeriod === null ? $target : $this->bound($genesisPeriod, $target);
    }

    private function bound(Period $genesisPeriod, Period $target): Period
    {
        $maxPeriod = $this->periods->containing($this->clock->now());
        for ($i = 0; $i < self::FUTURE_HORIZON_PERIODS; $i++) {
            $maxPeriod = $this->periods->next($maxPeriod);
        }

        if ($target->start->greaterThan($maxPeriod->start)) {
            $target = $maxPeriod;
        }

        return $target->start->lessThan($genesisPeriod->start) ? $genesisPeriod : $target;
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

        return SafeDate::parseOrNull(self::toString($raw)) ?? $this->earliestEnvelopeActivityFor($user);
    }

    // The earliest month the reader touched an envelope at all, which moving
    // money is: read from assignments alone, a reader whose whole history was
    // moves had no genesis, so every move folded to nought while the button
    // that made it kept confirming them.
    private function earliestEnvelopeActivityFor(User $user): ?CarbonImmutable
    {
        $earliest = null;

        foreach (['envelope_assignments', 'envelope_moves'] as $table) {
            $candidate = $this->db->connection()
                ->table($table)
                ->where('user_id', $user->id)
                ->min('period_start');

            if (! is_string($candidate)) {
                continue;
            }

            $parsed = SafeDate::parseOrNull($candidate);
            if ($parsed instanceof CarbonImmutable && ($earliest === null || $parsed->lessThan($earliest))) {
                $earliest = $parsed;
            }
        }

        return $earliest;
    }

    /**
     * @param  array<int, int>  $assignedByCategory
     * @param  array<int, int>  $movedByCategory
     * @param  array<int, int>  $carriedIn
     * @param  array<int, array{spent: int, unconverted: int}>  $spendByCategory
     */
    private function foldPeriod(
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

            $mode = OverspendMode::tryFrom($context->overspendModeByCategory[$categoryId] ?? '')
                ?? self::DEFAULT_OVERSPEND_MODE;
            $notifyThreshold = $context->notifyThresholdByCategory[$categoryId] ?? self::DEFAULT_NOTIFY_THRESHOLD_PERCENT;

            // The overspend modes differ in who absorbs a negative envelope:
            // reduce_to_budget hands the shortfall to the shared pool once per
            // period, carry_negative leaves it in the envelope and off the pool.
            if ($available < 0 && $mode->absorbsShortfallIntoPool()) {
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
                categoryPath: $naming['path'],
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

    // Reuses the shared spend query: a fresh GROUP BY would double-count a split
    // transaction, already legs union unsplit parents. Buckets are converted
    // into the currency the fold runs in, and one the rate table cannot reach
    // stays out of it, surfaced beside the row rather than counted at par.
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
            $buckets = self::bucketsForPeriod($byDay, $period);
            $bucketsByPeriod[$period->start->toDateString()] = $buckets;

            foreach ($buckets as $byCurrency) {
                $currencies = array_merge($currencies, array_keys($byCurrency));
            }
        }

        // One rate lookup for the walk: the base currency never changes across
        // it, so asking per period was the same answer fetched N times.
        $rates = $this->fx->ratesTo($currencies, $currency);

        $spendByPeriod = [];
        foreach ($bucketsByPeriod as $periodKey => $buckets) {
            $spendByPeriod[$periodKey] = $this->spendFromBuckets($buckets, $currency, $rates);
        }

        return $spendByPeriod;
    }

    // Both ends are 'Y-m-d', so the string order is the calendar order and the
    // exclusive end has to be compared with >=: a day equal to it belongs to
    // the next period, and a period that claimed it counted the same spend
    // twice across the walk.
    /**
     * @param  array<string, array<string, int>>  $byDay
     * @return array<int, array<string, int>>
     */
    private static function bucketsForPeriod(array $byDay, Period $period): array
    {
        $startKey = $period->start->toDateString();
        $endKey = $period->endExclusive->toDateString();

        $buckets = [];
        foreach ($byDay as $day => $keys) {
            if ($day < $startKey || $day >= $endKey) {
                continue;
            }

            foreach ($keys as $key => $minor) {
                [$categoryId, $bucketCurrency] = explode('|', self::toString($key), 2) + [1 => ''];
                $buckets[(int) $categoryId][$bucketCurrency]
                    = ($buckets[(int) $categoryId][$bucketCurrency] ?? 0) + $minor;
            }
        }

        return $buckets;
    }

    // The unconverted figure is summed from the buckets the rate table could
    // not reach, not from the difference between two totals: a converted total
    // is rounded and the subtraction would report the rounding as unpriced.
    /**
     * @param  array<int, array<string, int>>  $buckets
     * @param  array<string, string>  $rates
     * @return array<int, array{spent: int, unconverted: int}>
     */
    private function spendFromBuckets(array $buckets, string $currency, array $rates): array
    {
        $spend = [];
        foreach ($buckets as $categoryId => $byCurrency) {
            $converted = $this->fx->withRates($byCurrency, $currency, $rates);

            $unreached = 0;
            foreach ($converted->unconverted as $code) {
                $unreached += $byCurrency[$code] ?? 0;
            }

            $spend[$categoryId] = ['spent' => $converted->minor, 'unconverted' => $unreached];
        }

        return $spend;
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

    // Built backwards from the target so the month asked for is always folded.
    // Walking forward from genesis, a cap reached first left the target
    // unfolded and the grid answered with no envelopes at all — the empty
    // state that tells a reader to add an expense category, at two dozen.
    /**
     * @return non-empty-list<Period>
     */
    private function walkPeriods(User $user, Period $genesis, Period $target): array
    {
        $periods = [$target];
        $cursor = $target;

        while ($cursor->start->greaterThan($genesis->start)) {
            if (count($periods) >= self::MAX_WALK_PERIODS) {
                $this->log->warning('CarryoverQuery fold reached its walk cap; carry into the oldest period it folded is dropped.', [
                    'user_id' => $user->id,
                    'genesis' => $genesis->start->toDateString(),
                    'folded_from' => $cursor->start->toDateString(),
                    'target' => $target->start->toDateString(),
                    'max_walk_periods' => self::MAX_WALK_PERIODS,
                ]);

                break;
            }

            $cursor = $this->periods->previous($cursor);
            $periods[] = $cursor;
        }

        return array_reverse($periods);
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
