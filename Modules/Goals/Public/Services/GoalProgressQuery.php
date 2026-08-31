<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\SafeDate;
use Modules\FX\Public\Dto\ConvertedTotal;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Dto\GoalProgressRow;
use Modules\Goals\Public\Enums\GoalProgressState;
use Modules\Goals\Public\Enums\GoalStatus;
use Modules\Ledger\Public\Support\OutwardSpend;
use Modules\Pots\Public\Services\PotBalanceQuery;
use stdClass;

final readonly class GoalProgressQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private CrossCurrencyTotal $fx,
        private GoalProjectionService $projection,
        private PotBalanceQuery $potBalance,
    ) {}

    /**
     * @return list<GoalProgressRow>
     */
    public function forUser(User $user): array
    {
        return $this->loadRows($user, GoalStatus::Active, GoalStatus::Completed);
    }

    /**
     * @return list<GoalProgressRow>
     */
    public function archivedForUser(User $user): array
    {
        return $this->loadRows($user, GoalStatus::Archived);
    }

    /**
     * @return list<GoalProgressRow>
     */
    private function loadRows(User $user, GoalStatus ...$statuses): array
    {
        $goalRows = $this->db->connection()->table('goals')
            ->where('user_id', $user->id)
            ->whereIn('status', array_map(static fn (GoalStatus $status): string => $status->value, $statuses))
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

        $linkedPots = $this->potBalance->linkedPotBalancesForUser($user);
        $attributed = $this->attributedAmountsByGoalId(
            $user,
            array_values(array_map(static fn (stdClass $row): int => self::toInt($row->id), $goalRows->all())),
        );

        $ratesByTarget = $this->ratesByTargetCurrency(array_values($goalRows->all()), $linkedPots, $attributed);

        // One read of today for the whole list, threaded into the projection:
        // taken seven times per goal, a render that straddles midnight dated
        // one goal from yesterday and the next from today.
        $today = CarbonImmutable::today();

        $rows = [];
        foreach ($goalRows as $row) {
            $rows[] = $this->buildRow($row, $linkedPots, $attributed, $user, $ratesByTarget, $today);
        }

        return $rows;
    }

    // One lookup per (source currency, goal currency) pair for the whole list:
    // the level and the projection each read every attribution, and the service
    // behind a conversion reads the whole exchange_rates table per call.
    /**
     * @param  list<stdClass>  $goalRows
     * @param  array<int, array{balance: int, currency: string, potId: int, hasMovements: bool}>  $linkedPots
     * @param  array<int, list<array{amountMinor: int, currency: string, postedAt: string}>>  $attributed
     * @return array<string, array<string, string>>
     */
    private function ratesByTargetCurrency(array $goalRows, array $linkedPots, array $attributed): array
    {
        $sources = [];
        foreach ($linkedPots as $pot) {
            $sources[$pot['currency']] = true;
        }
        foreach ($attributed as $contributions) {
            foreach ($contributions as $contribution) {
                $sources[$contribution['currency']] = true;
            }
        }

        $ratesByTarget = [];
        foreach ($goalRows as $row) {
            $target = self::toString($row->target_currency);
            if (! isset($ratesByTarget[$target])) {
                $ratesByTarget[$target] = $this->fx->ratesTo(array_keys($sources), $target);
            }
        }

        return $ratesByTarget;
    }

    /**
     * @param  array<int, array{balance: int, currency: string, potId: int, hasMovements: bool}>  $linkedPots
     * @param  array<int, list<array{amountMinor: int, currency: string, postedAt: string}>>  $attributed
     * @param  array<string, array<string, string>>  $ratesByTarget
     */
    private function buildRow(stdClass $row, array $linkedPots, array $attributed, User $user, array $ratesByTarget, CarbonImmutable $today): GoalProgressRow
    {
        $goal = $this->hydrateGoal($row);
        $goalId = self::toInt($row->id);
        $targetCurrency = self::toString($row->target_currency);
        $linkedPot = $linkedPots[$goalId] ?? null;
        $rates = $ratesByTarget[$targetCurrency] ?? [];
        $contributions = $attributed[$goalId] ?? [];

        $contributed = $linkedPot !== null
            ? $this->potContribution($linkedPot, $targetCurrency, $rates)
            : $this->attributedContribution($contributions, $targetCurrency, $rates);

        $contributedMinor = $contributed->minor;
        $targetMinor = self::toInt($row->target_minor);
        $fractionComplete = OutwardSpend::share($contributedMinor, $targetMinor);

        $progressState = match (true) {
            $contributedMinor >= $targetMinor => GoalProgressState::Reached->value,
            $today->gt($goal->target_date) => GoalProgressState::Overdue->value,
            default => GoalProgressState::InProgress->value,
        };

        ['date' => $projectedDate, 'beyondHorizon' => $beyondHorizon, 'stalled' => $stalled] =
            $this->projection->project($goal, $contributedMinor, $user, $linkedPot, $contributions, $rates, $today);

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
            hasContributions: $linkedPot !== null ? $linkedPot['hasMovements'] : $contributions !== [],
            unconverted: $contributed->unconverted,
        );
    }

    /**
     * @param  array{balance: int, currency: string, potId: int, hasMovements: bool}  $linkedPot
     * @param  array<string, string>  $rates
     */
    private function potContribution(array $linkedPot, string $targetCurrency, array $rates): ConvertedTotal
    {
        if ($linkedPot['currency'] === '') {
            return new ConvertedTotal(minor: $linkedPot['balance'], currency: $targetCurrency, unconverted: []);
        }

        return $this->fx->withRates([$linkedPot['currency'] => $linkedPot['balance']], $targetCurrency, $rates);
    }

    /**
     * @param  list<array{amountMinor: int, currency: string, postedAt: string}>  $contributions
     * @param  array<string, string>  $rates
     */
    private function attributedContribution(array $contributions, string $targetCurrency, array $rates): ConvertedTotal
    {
        $byCurrency = [];
        foreach ($contributions as $contribution) {
            $byCurrency[$contribution['currency']]
                = ($byCurrency[$contribution['currency']] ?? 0) + $contribution['amountMinor'];
        }

        return $this->fx->withRates($byCurrency, $targetCurrency, $rates);
    }

    // An attribution is the user's own statement that this transaction funds this
    // goal, so it counts whenever it posted. start_date bounds only the
    // projection's observation window, never the sum.
    /**
     * @param  list<int>  $goalIds
     * @return array<int, list<array{amountMinor: int, currency: string, postedAt: string}>>
     */
    private function attributedAmountsByGoalId(User $user, array $goalIds): array
    {
        $rows = $this->db->connection()->table('goal_contributions')
            ->join('transactions', 'goal_contributions.transaction_id', '=', 'transactions.id')
            ->where('goal_contributions.user_id', $user->id)
            ->whereIn('goal_contributions.goal_id', $goalIds)
            // The SETTLED pair: the money that actually moved on the account.
            // The original pair would be re-converted at today's rate, so the
            // bar and the statement disagreed by whatever the rate had done.
            ->get([
                'goal_contributions.goal_id',
                'transactions.settled_amount_minor',
                'transactions.settled_currency',
                'transactions.posted_at',
            ]);

        $byGoal = [];
        foreach ($rows as $row) {
            $byGoal[self::toInt($row->goal_id)][] = [
                'amountMinor' => self::toInt($row->settled_amount_minor),
                'currency' => self::toString($row->settled_currency),
                'postedAt' => self::toString($row->posted_at),
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
            'target_date' => self::toString($row->target_date),
            'status' => self::toString($row->status),
        ]);

        return $goal;
    }

    // The driver hands back 'Y-m-d' or 'Y-m-d H:i:s' depending on which
    // column it came from, so the shape is parsed rather than assumed.
    private static function toDateStr(mixed $value): string
    {
        return SafeDate::parseOrNull(self::toString($value))?->toDateString() ?? '';
    }
}
