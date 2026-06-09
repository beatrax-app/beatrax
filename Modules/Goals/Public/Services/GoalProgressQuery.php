<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Dto\GoalProgressRow;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Pots\Public\Services\PotBalanceQuery;
use stdClass;

/**
 * Read model for the Goals page and dashboard card.
 *
 * `forUser()` returns the user's active and completed goals (excluding archived
 * — those are returned by the sibling `archivedForUser()` for Plan 04's
 * archived-goals disclosure).
 *
 * Contribution sum: credits (type IN transfer_in, income) on the linked account
 * posted on/after the goal's start_date, each base-converted via
 * `ExchangeRateService::convertToBase()`. Unlinked goals report 0 contributed.
 *
 * All reads go through raw `DatabaseManager` queries to stay clean under
 * `phpstan-strict-rules`' `staticMethod.dynamicCall` rule (the same pattern
 * as BudgetProgressQuery and UncategorizedTriageQuery — Eloquent-free read path,
 * Eloquent only for the write path).
 *
 * Cross-user isolation: every raw `goals` read carries an explicit
 * `->where('user_id', $user->id)` guard; raw `transactions` reads carry the same.
 * (T-02-04).
 *
 * No float money: `fractionComplete` is an int ratio (contributedMinor /
 * targetMinor), never a Money->float() call (T-02-06).
 */
final class GoalProgressQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ExchangeRateService $fx,
        private readonly GoalProjectionService $projection,
        private readonly PotBalanceQuery $potBalance,
    ) {}

    /**
     * Returns active and completed goals for the user, each with its
     * contribution progress and projected finish date.
     *
     * @return list<GoalProgressRow>
     */
    public function forUser(User $user): array
    {
        return $this->loadRows($user, 'active', 'completed');
    }

    /**
     * Returns archived goals for the user — consumed by Plan 04's archived
     * goals disclosure section (D-09).
     *
     * @return list<GoalProgressRow>
     */
    public function archivedForUser(User $user): array
    {
        return $this->loadRows($user, 'archived');
    }

    /**
     * Internal: loads goals matching the given statuses and maps them to rows.
     *
     * @return list<GoalProgressRow>
     */
    private function loadRows(User $user, string ...$statuses): array
    {
        $connection = $this->db->connection();

        // Fetch goals via raw DatabaseManager to stay clean under phpstan-strict-rules'
        // staticMethod.dynamicCall constraint. Left-join accounts so account_name
        // arrives in a single query with no N+1 expansion.
        $goalRows = $connection->table('goals')
            ->leftJoin('accounts', 'goals.account_id', '=', 'accounts.id')
            ->where('goals.user_id', $user->id)
            ->whereIn('goals.status', $statuses)
            ->orderBy('goals.id')
            ->get([
                'goals.id',
                'goals.name',
                'goals.account_id',
                'goals.target_minor',
                'goals.target_currency',
                'goals.start_date',
                'goals.target_date',
                'goals.status',
                'accounts.name as account_name',
            ]);

        if ($goalRows->isEmpty()) {
            return [];
        }

        // D-10: batch-load pot balances keyed by goal_id — avoids N+1 in the loop.
        $linkedPotBalances = $this->potBalance->linkedPotBalancesForUser($user);

        // Build a lightweight Goal model per row so GoalProjectionService can
        // consume the model's typed properties (start_date as CarbonImmutable,
        // account_id, target_minor, etc.) without re-implementing casting here.
        $rows = [];
        foreach ($goalRows as $row) {
            $goal = $this->hydrateGoal($row);
            $accountName = isset($row->account_name) && is_string($row->account_name)
                ? $row->account_name
                : null;

            // D-10: if this goal has an active linked pot, use the pot's balance
            // as contributedMinor (FX-converted into target_currency when needed).
            // Otherwise fall back to the Phase 2 sumContributions() path.
            $goalId = self::toInt($row->id);
            if (isset($linkedPotBalances[$goalId])) {
                $potMinor = $linkedPotBalances[$goalId];
                $potCurrency = $this->potBalance->currencyForLinkedPot($goalId, $user);
                $targetCurrency = self::toStr($row->target_currency);

                if ($potCurrency !== null && $potCurrency !== $targetCurrency) {
                    $money = Money::ofMinor($potMinor, $potCurrency);
                    $contributedMinor = $this->fx->convertToBase($money, $targetCurrency)->converted->toMinor();
                } else {
                    $contributedMinor = $potMinor;
                }
            } else {
                $contributedMinor = $this->sumContributions($goal, $user);
            }
            $targetMinor = self::toInt($row->target_minor);
            $fractionComplete = $targetMinor > 0 ? $contributedMinor / $targetMinor : 0.0;

            $progressState = match (true) {
                $contributedMinor >= $targetMinor => 'reached',
                CarbonImmutable::today()->gt($goal->target_date) => 'overdue',
                default => 'in_progress',
            };

            ['date' => $projectedDate, 'beyondHorizon' => $beyondHorizon] =
                $this->projection->project($goal, $contributedMinor, $user);

            $rows[] = new GoalProgressRow(
                id: self::toInt($row->id),
                name: self::toStr($row->name),
                accountId: $row->account_id !== null ? self::toInt($row->account_id) : null,
                accountName: $accountName,
                targetMinor: $targetMinor,
                contributedMinor: $contributedMinor,
                currency: self::toStr($row->target_currency),
                fractionComplete: $fractionComplete,
                targetDate: self::toDateStr($row->target_date),
                status: self::toStr($row->status),
                progressState: $progressState,
                projectedFinishDate: $projectedDate,
                projectionBeyondHorizon: $beyondHorizon,
            );
        }

        return $rows;
    }

    /**
     * Hydrates a Goal Eloquent model from a raw stdClass row so that
     * model casts (immutable_date for start_date / target_date) and typed
     * properties are available to GoalProjectionService without repeating
     * the casting logic here.
     */
    private function hydrateGoal(stdClass $row): Goal
    {
        $goal = new Goal;
        $goal->forceFill([
            'id' => self::toInt($row->id),
            'user_id' => null, // not loaded on the read path; projection takes $user explicitly
            'account_id' => $row->account_id !== null ? self::toInt($row->account_id) : null,
            'name' => self::toStr($row->name),
            'target_minor' => self::toInt($row->target_minor),
            'target_currency' => self::toStr($row->target_currency),
            'start_date' => self::toStr($row->start_date),
            'target_date' => isset($row->target_date) ? self::toStr($row->target_date) : CarbonImmutable::today()->addYear()->toDateString(),
            'status' => self::toStr($row->status),
        ]);

        return $goal;
    }

    /**
     * Sums credits (transfer_in, income) on the linked account since the goal's
     * start_date, converted into the goal's own `target_currency` (NOT the user's
     * current base currency — D-05 makes target_currency immutable so it can
     * diverge from base_currency; numerator and denominator must share a unit so
     * fractionComplete, the "reached" flip, and the rendered figure are all
     * consistent). Returns 0 for unlinked goals.
     *
     * Every raw read carries an explicit user_id guard (T-02-04).
     */
    private function sumContributions(Goal $goal, User $user): int
    {
        if ($goal->account_id === null) {
            return 0;
        }

        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $goal->account_id)
            ->whereIn('type', ['transfer_in', 'income'])
            ->where('posted_at', '>=', $goal->start_date)
            ->get(['amount_minor', 'currency']);

        $contributedMinor = 0;
        foreach ($rows as $r) {
            $money = Money::ofMinor(self::toInt($r->amount_minor), self::toStr($r->currency));
            $result = $this->fx->convertToBase($money, $goal->target_currency);
            $contributedMinor += $result->converted->toMinor();
        }

        return $contributedMinor;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    /**
     * Normalises a raw DB date value to a 'Y-m-d' string suitable for a
     * `<input type="date">` prefill, regardless of whether the driver returns
     * 'Y-m-d' or 'Y-m-d H:i:s'. Returns '' for an empty/unparseable value.
     */
    private static function toDateStr(mixed $value): string
    {
        $raw = self::toStr($value);
        if ($raw === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($raw)->toDateString();
        } catch (\Throwable) {
            return '';
        }
    }
}
