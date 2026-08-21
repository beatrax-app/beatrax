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
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\SpendByCategoryQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Ledger\Public\ValueObjects\Money;
use Psr\Log\LoggerInterface;

final class CarryoverQuery
{
    use CoercesScalars;

    private const CURRENCY = 'EUR';

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
    ) {}

    /**
     * @return array<int, EnvelopeRow>
     */
    private function unstartedRows(User $user): array
    {
        $settings = $this->envelopeSettings($user);
        $rows = [];

        foreach ($this->budgetProgress->expenseCategories($user) as $categoryId => $categoryName) {
            $rows[$categoryId] = new EnvelopeRow(
                categoryId: $categoryId,
                categoryName: $categoryName,
                assignedMinor: 0,
                spentMinor: 0,
                carriedInMinor: 0,
                netMovedMinor: 0,
                availableMinor: 0,
                overspendMode: $settings['modes'][$categoryId] ?? self::DEFAULT_OVERSPEND_MODE,
                currency: self::CURRENCY,
                notifyThresholdPercent: $settings['thresholds'][$categoryId] ?? self::DEFAULT_NOTIFY_THRESHOLD_PERCENT,
            );
        }

        return $rows;
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
            return [
                'toBudgetMinor' => $this->glance->incomeForPeriod($user, $target, self::CURRENCY),
                'overspentCount' => 0,
                'rows' => $this->unstartedRows($user),
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
            // Back-navigation must never read pre-activation history.
            $targetBounded = $genesisPeriod;
        }

        $periodsWalk = $this->walkPeriods($genesisPeriod, $targetBounded);

        $expenseCategories = $this->budgetProgress->expenseCategories($user);

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

        // An unreadable stamp must not read as "never started".
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
     */
    private function foldPeriod(
        User $user,
        Period $period,
        FoldContext $context,
        array $assignedByCategory,
        array $movedByCategory,
        int $poolCarry,
        array $carriedIn,
    ): FoldStep {
        // Reuses the shared query: a fresh GROUP BY here would double-count a
        // split transaction, which is already legs ∪ unsplit parents.
        $spentByKey = $this->spendByCategory->forUserAndPeriodByCurrency($user->id, $period);
        $income = $this->glance->incomeForPeriod($user, $period, self::CURRENCY);

        $totalAssignedMoney = Money::ofMinor(0, self::CURRENCY);
        foreach ($assignedByCategory as $assignedMinor) {
            $totalAssignedMoney = $totalAssignedMoney->plus(Money::ofMinor($assignedMinor, self::CURRENCY));
        }

        $toBudgetMoney = Money::ofMinor($income, self::CURRENCY)
            ->plus(Money::ofMinor($poolCarry, self::CURRENCY))
            ->minus($totalAssignedMoney);

        $nextCarriedIn = [];
        $shortfallMoney = Money::ofMinor(0, self::CURRENCY);
        $rows = [];
        $overspentCount = 0;

        foreach ($context->expenseCategories as $categoryId => $categoryName) {
            $assigned = $assignedByCategory[$categoryId] ?? 0;
            $moved = $movedByCategory[$categoryId] ?? 0;
            $spent = $spentByKey["{$categoryId}|".self::CURRENCY] ?? 0;
            $carriedInForCategory = $carriedIn[$categoryId] ?? 0;
            $nonEurSpent = $this->sumNonEurSpent($spentByKey, $categoryId);

            $availableMoney = Money::ofMinor($assigned, self::CURRENCY)
                ->plus(Money::ofMinor($carriedInForCategory, self::CURRENCY))
                ->plus(Money::ofMinor($moved, self::CURRENCY))
                ->minus(Money::ofMinor($spent, self::CURRENCY));
            $available = $availableMoney->toMinor();

            $mode = $context->overspendModeByCategory[$categoryId] ?? self::DEFAULT_OVERSPEND_MODE;
            $notifyThreshold = $context->notifyThresholdByCategory[$categoryId] ?? self::DEFAULT_NOTIFY_THRESHOLD_PERCENT;

            if ($available < 0 && $mode === self::DEFAULT_OVERSPEND_MODE) {
                // reduce_to_budget: the pool eats the shortfall once per period.
                $shortfallMoney = $shortfallMoney->plus($availableMoney);
                $nextCarriedIn[$categoryId] = 0;
            } else {
                // carry_negative keeps the negative in the envelope, off the pool.
                $nextCarriedIn[$categoryId] = $available;
            }

            if ($available < 0) {
                $overspentCount++;
            }

            $rows[$categoryId] = new EnvelopeRow(
                categoryId: $categoryId,
                categoryName: $categoryName,
                assignedMinor: $assigned,
                spentMinor: $spent,
                carriedInMinor: $carriedInForCategory,
                netMovedMinor: $moved,
                availableMinor: $available,
                overspendMode: $mode,
                currency: self::CURRENCY,
                nonEurSpentMinor: $nonEurSpent,
                notifyThresholdPercent: $notifyThreshold,
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
    private function sumNonEurSpent(array $spentByKey, int $categoryId): int
    {
        // Surfaced beside the fold, never folded into it: the fold is EUR-only,
        // so a converted figure would drift against the ledger.
        $nonEurSpent = 0;
        $prefix = "{$categoryId}|";
        foreach ($spentByKey as $key => $minor) {
            $keyStr = self::toString($key);
            if (str_starts_with($keyStr, $prefix) && ! str_ends_with($keyStr, '|'.self::CURRENCY)) {
                $nonEurSpent += self::toInt($minor);
            }
        }

        return $nonEurSpent;
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
            ->get(['category_id', 'period_start', 'assigned_minor']);

        $byPeriod = [];
        foreach ($rows as $row) {
            $periodKey = self::periodKeyFromRaw($row->period_start);
            $categoryId = self::toInt($row->category_id);
            $byPeriod[$periodKey][$categoryId] = self::toInt($row->assigned_minor);
        }

        return $byPeriod;
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
            ->groupBy('period_start', 'category_id')
            ->get(['period_start', 'category_id', $connection->raw('SUM(amount_minor) AS net_minor')]);

        $byPeriod = [];
        foreach ($rows as $row) {
            $periodKey = self::periodKeyFromRaw($row->period_start);
            $categoryId = self::toInt($row->category_id);
            $byPeriod[$periodKey][$categoryId] = self::toInt($row->net_minor);
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
