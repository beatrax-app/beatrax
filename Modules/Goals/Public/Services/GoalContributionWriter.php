<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Events\GoalContributionMutated;

final class GoalContributionWriter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
    ) {}

    // A goal or transaction the caller does not own writes nothing rather than
    // throwing: this is reachable straight from the browser, and an error would
    // confirm the row exists.
    /**
     * @return bool whether the attribution exists afterwards — false only when
     *              the goal or transaction is not the user's
     */
    public function attribute(User $user, int $goalId, int $transactionId): bool
    {
        if (! $this->ownsBoth($user, $goalId, $transactionId)) {
            return false;
        }

        return $this->insertAndAnnounce($user, $goalId, $transactionId);
    }

    /**
     * @return bool whether a contribution row was removed
     */
    public function detach(User $user, int $goalId, int $transactionId): bool
    {
        $contributionId = $this->contributionId($user, $goalId, $transactionId);
        if ($contributionId === null) {
            return false;
        }

        $this->db->connection()
            ->table('goal_contributions')
            ->where('id', $contributionId)
            ->delete();

        $this->events->dispatch(new GoalContributionMutated(
            contributionId: $contributionId,
            userId: $user->id,
            mutationType: 'delete',
        ));

        return true;
    }

    private function insertAndAnnounce(User $user, int $goalId, int $transactionId): bool
    {
        $now = $this->clock->now();

        $inserted = $this->db->connection()->table('goal_contributions')->insertOrIgnore([
            'user_id' => $user->id,
            'goal_id' => $goalId,
            'transaction_id' => $transactionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return true;
        }

        $contributionId = $this->contributionId($user, $goalId, $transactionId);
        if ($contributionId === null) {
            return false;
        }

        $this->events->dispatch(new GoalContributionMutated(
            contributionId: $contributionId,
            userId: $user->id,
            mutationType: 'create',
            dirtyFields: [
                'user_id' => $user->id,
                'goal_id' => $goalId,
                'transaction_id' => $transactionId,
            ],
        ));

        return true;
    }

    private function ownsBoth(User $user, int $goalId, int $transactionId): bool
    {
        $connection = $this->db->connection();

        $ownsGoal = $connection->table('goals')
            ->where('user_id', $user->id)
            ->where('id', $goalId)
            ->exists();

        if (! $ownsGoal) {
            return false;
        }

        return $connection->table('transactions')
            ->where('user_id', $user->id)
            ->where('id', $transactionId)
            ->exists();
    }

    private function contributionId(User $user, int $goalId, int $transactionId): ?int
    {
        $id = $this->db->connection()
            ->table('goal_contributions')
            ->where('user_id', $user->id)
            ->where('goal_id', $goalId)
            ->where('transaction_id', $transactionId)
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
