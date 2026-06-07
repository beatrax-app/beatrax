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
 * Cross-user isolation: every raw `transactions` read carries an explicit
 * `->where('user_id', $user->id)` guard; the `Goal` model is loaded through the
 * `BelongsToUser`-scoped query so a foreign goal_id resolves to nothing (T-02-04).
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
    ) {}

    /**
     * Returns active and completed goals for the user, each with its
     * contribution progress and projected finish date.
     *
     * @return list<GoalProgressRow>
     */
    public function forUser(User $user): array
    {
        $goals = Goal::query()
            ->whereIn('status', ['active', 'completed'])
            ->with('account')
            ->get();

        if ($goals->isEmpty()) {
            return [];
        }

        $rows = [];
        foreach ($goals as $goal) {
            $rows[] = $this->mapGoal($goal, $user);
        }

        return $rows;
    }

    /**
     * Returns archived goals for the user — consumed by Plan 04's archived
     * goals disclosure section (D-09).
     *
     * @return list<GoalProgressRow>
     */
    public function archivedForUser(User $user): array
    {
        $goals = Goal::query()
            ->where('status', 'archived')
            ->with('account')
            ->get();

        if ($goals->isEmpty()) {
            return [];
        }

        $rows = [];
        foreach ($goals as $goal) {
            $rows[] = $this->mapGoal($goal, $user);
        }

        return $rows;
    }

    /**
     * Maps a single Goal to a GoalProgressRow DTO, shared by forUser() and archivedForUser().
     */
    private function mapGoal(Goal $goal, User $user): GoalProgressRow
    {
        $contributedMinor = $this->sumContributions($goal, $user);
        $targetMinor = self::toInt($goal->target_minor);
        $fractionComplete = $targetMinor > 0 ? $contributedMinor / $targetMinor : 0.0;

        $progressState = match (true) {
            $contributedMinor >= $targetMinor => 'reached',
            CarbonImmutable::today()->gt($goal->target_date) => 'overdue',
            default => 'in_progress',
        };

        ['date' => $projectedDate, 'beyondHorizon' => $beyondHorizon] =
            $this->projection->project($goal, $contributedMinor, $user);

        $account = $goal->account;

        return new GoalProgressRow(
            id: self::toInt($goal->id),
            name: self::toStr($goal->name),
            accountId: $goal->account_id !== null ? self::toInt($goal->account_id) : null,
            accountName: $account !== null ? self::toStr($account->name) : null,
            targetMinor: $targetMinor,
            contributedMinor: $contributedMinor,
            currency: self::toStr($goal->target_currency),
            fractionComplete: $fractionComplete,
            status: self::toStr($goal->status),
            progressState: $progressState,
            projectedFinishDate: $projectedDate,
            projectionBeyondHorizon: $beyondHorizon,
        );
    }

    /**
     * Sums base-converted credits (transfer_in, income) on the linked account
     * since the goal's start_date. Returns 0 for unlinked goals.
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
            $result = $this->fx->convertToBase($money, $user->base_currency);
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
}
