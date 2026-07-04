<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Budgets\Public\Dto\EnvelopeRow;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\SpendByCategoryQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * The genesis-to-target iterative fold — the one genuinely new algorithm in
 * Phase 13.2 (D-08/D-09/D-10). Walks every period from the user's envelope-
 * activation genesis anchor (`users.envelope_activated_at`) forward to the
 * requested target period, carrying forward exactly two pieces of running
 * state: a single unassigned pool (`poolCarry`) and a per-category
 * `carriedIn` map. Every input (assignments, moves, settings, spend, income)
 * is read fresh from current ledger state on every call — nothing is
 * persisted or incrementally mutated, which is what makes re-running an
 * unchanged import (or editing/splitting a past transaction) idempotent by
 * construction (Req 9).
 *
 * D-12a (implicit envelopes): every live expense category is iterated every
 * period — via `BudgetProgressQuery::expenseCategories()` — never just the
 * categories that happen to have an `envelope_assignments`/`envelope_settings`
 * row, so an unassigned-but-spending category still surfaces as an overspent
 * €0-assigned envelope instead of silently vanishing (Pitfall 5).
 *
 * D-12b (genesis): when `envelope_activated_at` is null (pre-cutover), this
 * returns an all-zero result rather than walking from account inception.
 *
 * D-12c (horizon): the walk is bounded `genesis..min(target, current+12)` —
 * it never walks further into the future than 12 periods past "now", closing
 * the unbounded-past/future-walk DoS surface (T-13.2-03-03).
 *
 * Every read carries an explicit `WHERE user_id = ?` (T-13.2-03-01) — no
 * reliance on a global UserScope for this cross-module Public service.
 *
 * NOTE on memoization: this class is bound as a request-lifetime singleton
 * (`BudgetsServiceProvider`) so the grid, the sticky to-budget header, and
 * the dashboard glance card share one container instance per HTTP request.
 * It deliberately does NOT cache fold results across separate calls to
 * `forUserAndPeriod()` keyed only by (userId, targetPeriodStart): the
 * Wave 0-pinned `CarryoverQueryTest`/`CarryoverQueryOverspendTest` suites
 * interleave writes (assign/unassign/toggle-overspend-mode) with repeated
 * reads of the SAME (user, period) via the SAME container instance, and a
 * literal instance-property cache would silently serve stale figures after
 * those writes — a correctness regression Req 9 (idempotent LIVE recompute)
 * exists specifically to prevent. Every call is a fresh, pure, request-scoped
 * fold over current data (batch-loaded per table, not per period); the
 * "memoized per request" property of D-09 is satisfied by the fold being
 * inexpensive and bounded (genesis..current+12), not by an unsafe result
 * cache. See 13.2-03-SUMMARY.md for the full rationale.
 */
final class CarryoverQuery
{
    private const CURRENCY = 'EUR';

    /** D-12c: future horizon bound, in periods past "now". */
    private const FUTURE_HORIZON_PERIODS = 12;

    /** Defensive cap on the number of periods a single fold will walk (T-13.2-03-03). */
    private const MAX_WALK_PERIODS = 1000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PeriodQuery $periods,
        private readonly SpendByCategoryQuery $spendByCategory,
        private readonly ThisPeriodAtAGlanceQuery $glance,
        private readonly BudgetProgressQuery $budgetProgress,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{toBudgetMinor: int, overspentCount: int, rows: array<int, EnvelopeRow>}
     */
    public function forUserAndPeriod(User $user, Period $target): array
    {
        $genesisInstant = $this->genesisAnchorFor($user);

        if ($genesisInstant === null) {
            // D-12b: no envelope activation yet — every period is
            // genesis-equivalent; nothing to fold over.
            return ['toBudgetMinor' => 0, 'overspentCount' => 0, 'rows' => []];
        }

        $genesisPeriod = $this->periods->containing($genesisInstant);
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
            // Nothing before genesis (D-12b) — clamp the walk to genesis
            // itself rather than walking backwards or reading pre-activation
            // history.
            $targetBounded = $genesisPeriod;
        }

        $periodsWalk = $this->walkPeriods($genesisPeriod, $targetBounded);

        $expenseCategories = $this->budgetProgress->expenseCategories($user);

        $assignedByPeriod = $this->batchAssignments($user, $genesisPeriod, $targetBounded);
        $movedByPeriod = $this->batchMoves($user, $genesisPeriod, $targetBounded);
        $overspendModeByCategory = $this->overspendModes($user);

        $poolCarry = 0;
        $carriedIn = [];
        foreach (array_keys($expenseCategories) as $categoryId) {
            $carriedIn[$categoryId] = 0;
        }

        $result = null;

        foreach ($periodsWalk as $period) {
            $periodKey = $period->start->toDateString();
            $assignedByCategory = $assignedByPeriod[$periodKey] ?? [];
            $movedByCategory = $movedByPeriod[$periodKey] ?? [];

            // Single round trip per period (already legs ∪ unsplit-parents
            // safe — never a fresh GROUP BY, per D-11 / the double-count
            // trap this phase must not reintroduce).
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

            foreach ($expenseCategories as $categoryId => $categoryName) {
                $assigned = $assignedByCategory[$categoryId] ?? 0;
                $moved = $movedByCategory[$categoryId] ?? 0;
                $spent = $spentByKey["{$categoryId}|".self::CURRENCY] ?? 0;
                $carriedInForCategory = $carriedIn[$categoryId] ?? 0;

                // CR-01: envelopes are EUR-only (D-25), but the ledger can
                // hold settled spend in other currencies (USD Google Play, a
                // non-EUR ICS merchant charge). Sum that dropped remainder so
                // the grid can SURFACE it rather than vanish it. It is
                // deliberately NOT added to $spent/$available and NOT counted
                // as an overspend — that would be a currency collapse, which
                // the multi-currency-from-v1 constraint forbids. This is a
                // "there is spend not shown here" flag, not a EUR figure.
                $nonEurSpent = 0;
                $prefix = "{$categoryId}|";
                foreach ($spentByKey as $key => $minor) {
                    $keyStr = self::toStr($key);
                    if (str_starts_with($keyStr, $prefix) && ! str_ends_with($keyStr, '|'.self::CURRENCY)) {
                        $nonEurSpent += self::toInt($minor);
                    }
                }

                $availableMoney = Money::ofMinor($assigned, self::CURRENCY)
                    ->plus(Money::ofMinor($carriedInForCategory, self::CURRENCY))
                    ->plus(Money::ofMinor($moved, self::CURRENCY))
                    ->minus(Money::ofMinor($spent, self::CURRENCY));
                $available = $availableMoney->toMinor();

                $mode = $overspendModeByCategory[$categoryId] ?? 'reduce_to_budget';

                if ($available < 0 && $mode === 'reduce_to_budget') {
                    // D-10: default toggle resets to 0 next period and debits
                    // the pool exactly once per overspent envelope per period.
                    $shortfallMoney = $shortfallMoney->plus($availableMoney);
                    $nextCarriedIn[$categoryId] = 0;
                } else {
                    // Positive availability rolls forward as-is; carry_negative
                    // keeps the negative in the envelope, never touching the pool.
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
                );
            }

            $poolCarryMoney = $toBudgetMoney->plus($shortfallMoney);
            $poolCarry = $poolCarryMoney->toMinor();
            $carriedIn = $nextCarriedIn;

            if ($period->start->equalTo($targetBounded->start)) {
                $result = [
                    'toBudgetMinor' => $toBudgetMoney->toMinor(),
                    'overspentCount' => $overspentCount,
                    'rows' => $rows,
                ];
            }
        }

        return $result ?? ['toBudgetMinor' => 0, 'overspentCount' => 0, 'rows' => []];
    }

    /**
     * Resolves the per-user genesis anchor via a raw, explicitly-scoped read
     * of `users.envelope_activated_at` (Pitfall 2 / D-12b) — read via the
     * query builder rather than the Eloquent `User` model so this Public
     * service never depends on `Modules\Core\Models\User` carrying a cast
     * for a column owned by this phase's migration.
     */
    private function genesisAnchorFor(User $user): ?CarbonImmutable
    {
        $raw = $this->db->connection()
            ->table('users')
            ->where('id', $user->id)
            ->value('envelope_activated_at');

        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(self::toStr($raw));
        } catch (\Throwable) {
            return null;
        }
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
     * Batch-loads every `envelope_assignments` row across the whole
     * genesis..target range in ONE query, bucketed by period in PHP
     * (RESEARCH.md performance shape — never one query per period per table).
     *
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
     * Batch-loads `SUM(amount_minor) GROUP BY period_start, category_id`
     * across the whole genesis..target range in ONE query.
     *
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
     * @return array<int, string> category_id => overspend_mode
     */
    private function overspendModes(User $user): array
    {
        $rows = $this->db->connection()
            ->table('envelope_settings')
            ->where('user_id', $user->id)
            ->get(['category_id', 'overspend_mode']);

        $modes = [];
        foreach ($rows as $row) {
            $modes[self::toInt($row->category_id)] = self::toStr($row->overspend_mode);
        }

        return $modes;
    }

    private static function periodKeyFromRaw(mixed $value): string
    {
        $raw = self::toStr($value);
        if ($raw === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($raw)->toDateString();
        } catch (\Throwable) {
            return $raw;
        }
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
