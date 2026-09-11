<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Goals\Public\Dto\GoalAttributionRow;
use Modules\Goals\Public\Enums\GoalStatus;
use Modules\Pots\Public\Services\PotBalanceQuery;

final readonly class GoalContributionQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private PotBalanceQuery $potBalance,
    ) {}

    // A pot-linked goal takes its whole progress figure from that pot, so an
    // attribution to one is discarded on the next render. It was offered here
    // anyway, and the transaction then reported the discarded claim as fact.
    /**
     * @return list<GoalAttributionRow>
     */
    public function attributableGoals(User $user): array
    {
        $query = $this->db->connection()
            ->table('goals')
            ->where('user_id', $user->id)
            ->where('status', GoalStatus::Active->value)
            ->orderBy('name')
            ->orderBy('id');

        $potFunded = $this->potBalance->goalIdsWithAnActivePot($user);
        if ($potFunded !== []) {
            $query->whereNotIn('id', $potFunded);
        }

        $rows = $query->get(['id', 'name']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = new GoalAttributionRow(
                goalId: self::toInt($row->id),
                goalName: self::toString($row->name),
            );
        }

        return $out;
    }

    // The third end. An attribution made before a pot took the goal over is
    // still on the row, and the progress figure has stopped reading it, so
    // listing it here is the screen stating a discarded claim as fact --
    // which is what the other two ends refuse in order to prevent.
    /**
     * @return list<GoalAttributionRow>
     */
    public function forTransaction(User $user, int $transactionId): array
    {
        $potFunded = $this->potBalance->goalIdsWithAnActivePot($user);

        $rows = $this->db->connection()
            ->table('goal_contributions')
            ->join('goals', 'goal_contributions.goal_id', '=', 'goals.id')
            ->where('goal_contributions.user_id', $user->id)
            ->where('goal_contributions.transaction_id', $transactionId)
            ->when($potFunded !== [], static function (Builder $query) use ($potFunded): void {
                $query->whereNotIn('goals.id', $potFunded);
            })
            ->orderBy('goals.name')
            ->orderBy('goals.id')
            ->get(['goals.id', 'goals.name']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = new GoalAttributionRow(
                goalId: self::toInt($row->id),
                goalName: self::toString($row->name),
            );
        }

        return $out;
    }
}
