<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\SafeDate;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Dto\GoalProgressRow;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Pots\Public\Services\PotBalanceQuery;
use stdClass;

final class GoalProgressQuery
{
    use CoercesScalars;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ExchangeRateService $fx,
        private readonly GoalProjectionService $projection,
        private readonly PotBalanceQuery $potBalance,
    ) {}

    /**
     * @return list<GoalProgressRow>
     */
    public function forUser(User $user): array
    {
        return $this->loadRows($user, 'active', 'completed');
    }

    /**
     * @return list<GoalProgressRow>
     */
    public function archivedForUser(User $user): array
    {
        return $this->loadRows($user, 'archived');
    }

    /**
     * @return list<GoalProgressRow>
     */
    private function loadRows(User $user, string ...$statuses): array
    {
        $goalRows = $this->db->connection()->table('goals')
            ->where('user_id', $user->id)
            ->whereIn('status', $statuses)
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'target_minor',
                'target_currency',
                'start_date',
                'target_date',
                'status',
            ]);

        if ($goalRows->isEmpty()) {
            return [];
        }

        // Batch-loaded up front: a per-goal follow-up is an N+1 across the page.
        $linkedPots = $this->potBalance->linkedPotBalancesForUser($user);
        $attributed = $this->attributedAmountsByGoalId(
            $user,
            array_values(array_map(static fn (stdClass $row): int => self::toInt($row->id), $goalRows->all())),
        );

        $rows = [];
        foreach ($goalRows as $row) {
            $rows[] = $this->buildRow($row, $linkedPots, $attributed, $user);
        }

        return $rows;
    }

    /**
     * @param  array<int, array{balance: int, currency: string, potId: int}>  $linkedPots
     * @param  array<int, list<array{amountMinor: int, currency: string}>>  $attributed
     */
    private function buildRow(stdClass $row, array $linkedPots, array $attributed, User $user): GoalProgressRow
    {
        $goal = $this->hydrateGoal($row);
        $goalId = self::toInt($row->id);
        $targetCurrency = self::toString($row->target_currency);
        $linkedPot = $linkedPots[$goalId] ?? null;

        $contributedMinor = $linkedPot !== null
            ? $this->potContribution($linkedPot, $targetCurrency)
            : $this->attributedContribution($attributed[$goalId] ?? [], $targetCurrency);

        $targetMinor = self::toInt($row->target_minor);
        $fractionComplete = $targetMinor > 0 ? $contributedMinor / $targetMinor : 0.0;

        $progressState = match (true) {
            $contributedMinor >= $targetMinor => 'reached',
            CarbonImmutable::today()->gt($goal->target_date) => 'overdue',
            default => 'in_progress',
        };

        ['date' => $projectedDate, 'beyondHorizon' => $beyondHorizon, 'stalled' => $stalled] =
            $this->projection->project($goal, $contributedMinor, $user, $linkedPot);

        return new GoalProgressRow(
            id: $goalId,
            name: self::toString($row->name),
            targetMinor: $targetMinor,
            contributedMinor: $contributedMinor,
            currency: $targetCurrency,
            fractionComplete: $fractionComplete,
            targetDate: self::toDateStr($row->target_date),
            status: self::toString($row->status),
            progressState: $progressState,
            projectedFinishDate: $projectedDate,
            projectionBeyondHorizon: $beyondHorizon,
            projectionStalled: $stalled,
        );
    }

    /**
     * @param  array{balance: int, currency: string, potId: int}  $linkedPot
     */
    private function potContribution(array $linkedPot, string $targetCurrency): int
    {
        if ($linkedPot['currency'] === '' || $linkedPot['currency'] === $targetCurrency) {
            return $linkedPot['balance'];
        }

        $money = Money::ofMinor($linkedPot['balance'], $linkedPot['currency']);

        return $this->fx->convertToBase($money, $targetCurrency)->converted->toMinor();
    }

    /**
     * @param  list<array{amountMinor: int, currency: string}>  $contributions
     */
    private function attributedContribution(array $contributions, string $targetCurrency): int
    {
        $total = 0;
        foreach ($contributions as $contribution) {
            $money = Money::ofMinor($contribution['amountMinor'], $contribution['currency']);
            $total += $this->fx->convertToBase($money, $targetCurrency)->converted->toMinor();
        }

        return $total;
    }

    // An attribution is the user's own statement that this transaction funds this
    // goal, so it counts whenever it posted. start_date bounds only the
    // projection's observation window, never the sum.
    /**
     * @param  list<int>  $goalIds
     * @return array<int, list<array{amountMinor: int, currency: string}>>
     */
    private function attributedAmountsByGoalId(User $user, array $goalIds): array
    {
        $rows = $this->db->connection()->table('goal_contributions')
            ->join('transactions', 'goal_contributions.transaction_id', '=', 'transactions.id')
            ->where('goal_contributions.user_id', $user->id)
            ->whereIn('goal_contributions.goal_id', $goalIds)
            ->get(['goal_contributions.goal_id', 'transactions.amount_minor', 'transactions.currency']);

        $byGoal = [];
        foreach ($rows as $row) {
            $byGoal[self::toInt($row->goal_id)][] = [
                'amountMinor' => self::toInt($row->amount_minor),
                'currency' => self::toString($row->currency),
            ];
        }

        return $byGoal;
    }

    // GoalProjectionService reads typed properties, so the raw row is hydrated
    // into a Goal rather than the casts being re-implemented here.
    private function hydrateGoal(stdClass $row): Goal
    {
        $goal = new Goal;
        $goal->forceFill([
            'id' => self::toInt($row->id),
            'user_id' => null,
            'name' => self::toString($row->name),
            'target_minor' => self::toInt($row->target_minor),
            'target_currency' => self::toString($row->target_currency),
            'start_date' => self::toString($row->start_date),
            'target_date' => isset($row->target_date) ? self::toString($row->target_date) : CarbonImmutable::today()->addYear()->toDateString(),
            'status' => self::toString($row->status),
        ]);

        return $goal;
    }

    // The driver returns 'Y-m-d' or 'Y-m-d H:i:s' depending on the column.
    private static function toDateStr(mixed $value): string
    {
        return SafeDate::parseOrNull(self::toString($value))?->toDateString() ?? '';
    }
}
