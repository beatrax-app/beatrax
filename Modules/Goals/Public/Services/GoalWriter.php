<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Exceptions\GoalNotFoundException;
use Modules\Goals\Public\Exceptions\InvalidGoalAmountException;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

/**
 * @link ../../../../.docs/features/goals/architecture.md
 */
final class GoalWriter
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @throws InvalidGoalAmountException when `$rawAmount` is invalid or non-positive.
     * @throws \InvalidArgumentException when `$accountId` is not owned by `$user`.
     */
    public function save(
        User $user,
        string $name,
        string $rawAmount,
        string $targetDate,
        ?int $accountId,
    ): Goal {
        $minor = $this->parseAmount($rawAmount);
        if ($minor === null) {
            throw new InvalidGoalAmountException('Invalid or non-positive target amount.');
        }

        $this->assertOwnedAccountOrNull($user, $accountId);

        // Always create a fresh goal — an updateOrCreate keyed on (user_id,
        // name, start_date) would silently overwrite an existing same-name
        // same-day goal (e.g. two "Holiday" goals). Bypass the global scope
        // so the authed $user stays authoritative regardless of guard state.
        return Goal::query()->withoutGlobalScope(UserScope::class)->create([
            'user_id' => $user->id,
            'name' => $name,
            'start_date' => CarbonImmutable::today()->toDateString(),
            'account_id' => $accountId,
            'target_minor' => $minor,
            'target_currency' => $user->base_currency,
            'target_date' => $targetDate,
            'status' => 'active',
        ]);
    }

    /**
     * @throws GoalNotFoundException when the goal is not found (cross-user / missing).
     * @throws InvalidGoalAmountException when `$rawAmount` is invalid or non-positive.
     * @throws \InvalidArgumentException when `$accountId` is not owned by `$user`.
     */
    public function update(
        User $user,
        int $goalId,
        string $name,
        string $rawAmount,
        string $targetDate,
        ?int $accountId,
    ): Goal {
        $goal = $this->findOwnedGoal($user, $goalId);
        if (! $goal instanceof Goal) {
            throw new GoalNotFoundException('Goal not found or not owned by user.');
        }

        $minor = $this->parseAmount($rawAmount);
        if ($minor === null) {
            throw new InvalidGoalAmountException('Invalid or non-positive target amount.');
        }

        $this->assertOwnedAccountOrNull($user, $accountId);

        $goal->name = $name;
        $goal->target_minor = $minor;
        $goal->target_date = CarbonImmutable::parse($targetDate);
        $goal->account_id = $accountId;
        $goal->save();

        return $goal;
    }

    public function markComplete(User $user, int $goalId): void
    {
        $goal = $this->findOwnedGoal($user, $goalId);
        if (! $goal instanceof Goal) {
            return;
        }

        $goal->status = 'completed';
        $goal->save();
    }

    public function archive(User $user, int $goalId): void
    {
        $goal = $this->findOwnedGoal($user, $goalId);
        if (! $goal instanceof Goal) {
            return;
        }

        $goal->status = 'archived';
        $goal->save();
    }

    public function restore(User $user, int $goalId): void
    {
        $goal = $this->findOwnedGoal($user, $goalId);
        if (! $goal instanceof Goal) {
            return;
        }

        $goal->status = 'active';
        $goal->save();
    }

    // Parses a user-entered positive amount to integer minor units — the
    // shared MoneyInput handles the Dutch/plain decimal forms — or null for a
    // blank, malformed, zero or negative entry.
    public function parseAmount(string $value): ?int
    {
        return MoneyInput::tryToPositiveMinor($value);
    }

    // Bypasses the global scope and re-asserts user_id explicitly so
    // ownership is independent of guard state — relying on the ambient
    // scope alone is a no-op (and a latent IDOR) in an unauthenticated
    // context. Returns null for a missing or cross-user id.
    private function findOwnedGoal(User $user, int $goalId): ?Goal
    {
        return Goal::query()
            ->withoutGlobalScope(UserScope::class)
            ->where('user_id', $user->id)
            ->find($goalId);
    }

    /**
     * @throws \InvalidArgumentException when `$accountId` is not owned by `$user`.
     */
    private function assertOwnedAccountOrNull(User $user, ?int $accountId): void
    {
        if ($accountId === null) {
            return;
        }

        $exists = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('id', $accountId)
            ->exists();

        if (! $exists) {
            throw new \InvalidArgumentException('Account not owned by the authenticated user.');
        }
    }
}
