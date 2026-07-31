<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Dto\GoalProgressRow;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Pots\Public\Services\PotBalanceQuery;
use stdClass;

/**
 * @link ../../../../.docs/features/goals/architecture.md
 */
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

        // Batch-load pot balances keyed by goal_id up front — avoids an N+1
        // lookup per row when resolving each goal's contribution.
        $linkedPotBalances = $this->potBalance->linkedPotBalancesForUser($user);

        $rows = [];
        foreach ($goalRows as $row) {
            $rows[] = $this->buildRow($row, $linkedPotBalances, $user);
        }

        return $rows;
    }

    /**
     * @param  array<int, array{balance: int, currency: string}>  $linkedPotBalances
     */
    private function buildRow(stdClass $row, array $linkedPotBalances, User $user): GoalProgressRow
    {
        // A lightweight Goal model per row lets GoalProjectionService consume
        // the model's typed properties (start_date as CarbonImmutable,
        // account_id, target_minor) without re-implementing casting here.
        $goal = $this->hydrateGoal($row);
        $accountName = isset($row->account_name) && is_string($row->account_name)
            ? $row->account_name
            : null;

        $contributedMinor = $this->resolveContributedMinor($row, $goal, $linkedPotBalances, $user);
        $targetMinor = self::toInt($row->target_minor);
        $fractionComplete = $targetMinor > 0 ? $contributedMinor / $targetMinor : 0.0;

        $progressState = match (true) {
            $contributedMinor >= $targetMinor => 'reached',
            CarbonImmutable::today()->gt($goal->target_date) => 'overdue',
            default => 'in_progress',
        };

        ['date' => $projectedDate, 'beyondHorizon' => $beyondHorizon] =
            $this->projection->project($goal, $contributedMinor, $user);

        return new GoalProgressRow(
            id: self::toInt($row->id),
            name: self::toString($row->name),
            accountId: $row->account_id !== null ? self::toInt($row->account_id) : null,
            accountName: $accountName,
            targetMinor: $targetMinor,
            contributedMinor: $contributedMinor,
            currency: self::toString($row->target_currency),
            fractionComplete: $fractionComplete,
            targetDate: self::toDateStr($row->target_date),
            status: self::toString($row->status),
            progressState: $progressState,
            projectedFinishDate: $projectedDate,
            projectionBeyondHorizon: $beyondHorizon,
        );
    }

    // A goal with an active linked pot draws its contribution from the pot's
    // balance (FX-converted into target_currency when they differ); otherwise
    // it falls back to the transaction-sum path.
    /**
     * @param  array<int, array{balance: int, currency: string}>  $linkedPotBalances
     */
    private function resolveContributedMinor(stdClass $row, Goal $goal, array $linkedPotBalances, User $user): int
    {
        $goalId = self::toInt($row->id);
        if (! isset($linkedPotBalances[$goalId])) {
            return $this->sumContributions($goal, $user);
        }

        $potMinor = $linkedPotBalances[$goalId]['balance'];
        $potCurrency = $linkedPotBalances[$goalId]['currency'];
        $targetCurrency = self::toString($row->target_currency);

        if ($potCurrency === '' || $potCurrency === $targetCurrency) {
            return $potMinor;
        }

        $money = Money::ofMinor($potMinor, $potCurrency);

        return $this->fx->convertToBase($money, $targetCurrency)->converted->toMinor();
    }

    // Hydrates a Goal Eloquent model from a raw stdClass row so model casts
    // and typed properties are available to GoalProjectionService without
    // repeating the casting logic here.
    private function hydrateGoal(stdClass $row): Goal
    {
        $goal = new Goal;
        $goal->forceFill([
            'id' => self::toInt($row->id),
            'user_id' => null,
            'account_id' => $row->account_id !== null ? self::toInt($row->account_id) : null,
            'name' => self::toString($row->name),
            'target_minor' => self::toInt($row->target_minor),
            'target_currency' => self::toString($row->target_currency),
            'start_date' => self::toString($row->start_date),
            'target_date' => isset($row->target_date) ? self::toString($row->target_date) : CarbonImmutable::today()->addYear()->toDateString(),
            'status' => self::toString($row->status),
        ]);

        return $goal;
    }

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
            $money = Money::ofMinor(self::toInt($r->amount_minor), self::toString($r->currency));
            $result = $this->fx->convertToBase($money, $goal->target_currency);
            $contributedMinor += $result->converted->toMinor();
        }

        return $contributedMinor;
    }

    // Normalises a raw DB date value to a 'Y-m-d' string, regardless of
    // whether the driver returns 'Y-m-d' or 'Y-m-d H:i:s'. Returns '' for an
    // empty/unparseable value.
    private static function toDateStr(mixed $value): string
    {
        $raw = self::toString($value);
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
