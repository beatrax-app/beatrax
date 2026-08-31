<?php

declare(strict_types=1);

namespace Modules\Goals\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Goals\Public\Services\GoalWriter;
use Modules\Pots\Public\Exceptions\PotNotFoundException;
use Modules\Pots\Public\Services\PotWriter;

// The goal write and the pot link/unlink/relink are one DB transaction, so a
// failed link rolls the goal mutation back with it: never an orphan goal, never
// a silently-lost previous pot link. It lives here and not in the component
// because that rule is about the domain, not about the page that triggers it.
final readonly class GoalPotLinkWriter
{
    public function __construct(
        private DatabaseManager $db,
        private GoalWriter $goals,
        private PotWriter $pots,
    ) {}

    /**
     * @throws PotNotFoundException when the pot id is missing, foreign or archived
     */
    public function create(User $user, string $name, string $rawAmount, string $targetDate, ?int $potId): void
    {
        $this->db->connection()->transaction(function () use ($user, $name, $rawAmount, $targetDate, $potId): void {
            $goal = $this->goals->save($user, $name, $rawAmount, $targetDate);

            if ($potId !== null) {
                $this->pots->linkGoal($user, $potId, $goal->id);
            }
        });
    }

    /**
     * @throws PotNotFoundException when either pot id is missing, foreign or archived
     */
    public function update(
        User $user,
        int $goalId,
        string $name,
        string $rawAmount,
        string $targetDate,
        ?int $newPotId,
        ?int $previousPotId,
    ): void {
        $this->db->connection()->transaction(function () use ($user, $goalId, $name, $rawAmount, $targetDate, $newPotId, $previousPotId): void {
            $this->goals->update($user, $goalId, $name, $rawAmount, $targetDate);

            $this->relink($user, $goalId, $newPotId, $previousPotId);
        });
    }

    private function relink(User $user, int $goalId, ?int $newPotId, ?int $previousPotId): void
    {
        if ($newPotId === $previousPotId) {
            return;
        }

        if ($previousPotId !== null) {
            $this->pots->linkGoal($user, $previousPotId, null);
        }

        if ($newPotId !== null) {
            $this->pots->linkGoal($user, $newPotId, $goalId);
        }
    }
}
