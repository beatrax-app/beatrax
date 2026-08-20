<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Goals\Public\Dto\GoalAttributionRow;
use Modules\Goals\Public\Enums\GoalStatus;

final class GoalContributionQuery
{
    use CoercesScalars;

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return list<GoalAttributionRow>
     */
    public function attributableGoals(User $user): array
    {
        $rows = $this->db->connection()
            ->table('goals')
            ->where('user_id', $user->id)
            ->where('status', GoalStatus::Active->value)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = new GoalAttributionRow(
                goalId: self::toInt($row->id),
                goalName: self::toString($row->name),
            );
        }

        return $out;
    }

    /**
     * @return list<GoalAttributionRow>
     */
    public function forTransaction(User $user, int $transactionId): array
    {
        $rows = $this->db->connection()
            ->table('goal_contributions')
            ->join('goals', 'goal_contributions.goal_id', '=', 'goals.id')
            ->where('goal_contributions.user_id', $user->id)
            ->where('goal_contributions.transaction_id', $transactionId)
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
