<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Enums\GoalStatus;
use Modules\Goals\Public\Exceptions\GoalNotFoundException;
use Modules\Goals\Public\Exceptions\InvalidGoalAmountException;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Sync\Public\Events\GoalMutated;

final class GoalWriter
{
    public function __construct(private readonly Dispatcher $events) {}

    /**
     * @throws InvalidGoalAmountException when `$rawAmount` is invalid or non-positive.
     */
    public function save(
        User $user,
        string $name,
        string $rawAmount,
        string $targetDate,
    ): Goal {
        $minor = $this->parseAmount($rawAmount);
        if ($minor === null) {
            throw new InvalidGoalAmountException('Invalid or non-positive target amount.');
        }

        // Always create a fresh goal — an updateOrCreate keyed on (user_id,
        // name, start_date) would silently overwrite an existing same-name
        // same-day goal (e.g. two "Holiday" goals). Bypass the global scope
        // so the authed $user stays authoritative regardless of guard state.
        $attributes = [
            'user_id' => $user->id,
            'name' => $name,
            'start_date' => CarbonImmutable::today()->toDateString(),
            'target_minor' => $minor,
            'target_currency' => $user->base_currency,
            'target_date' => $targetDate,
            'status' => GoalStatus::Active->value,
        ];

        $goal = Goal::query()->withoutGlobalScope(UserScope::class)->create($attributes);

        $this->capture($goal, $user, 'create', $attributes);

        return $goal;
    }

    /**
     * @throws GoalNotFoundException when the goal is not found (cross-user / missing).
     * @throws InvalidGoalAmountException when `$rawAmount` is invalid or non-positive.
     */
    public function update(
        User $user,
        int $goalId,
        string $name,
        string $rawAmount,
        string $targetDate,
    ): Goal {
        $goal = $this->findOwnedGoal($user, $goalId);
        if (! $goal instanceof Goal) {
            throw new GoalNotFoundException('Goal not found or not owned by user.');
        }

        $minor = $this->parseAmount($rawAmount);
        if ($minor === null) {
            throw new InvalidGoalAmountException('Invalid or non-positive target amount.');
        }

        $goal->name = $name;
        $goal->target_minor = $minor;
        $goal->target_date = CarbonImmutable::parse($targetDate);
        $goal->save();

        $this->capture($goal, $user, 'edit', [
            'name' => $name,
            'target_minor' => $minor,
            'target_date' => $targetDate,
        ]);

        return $goal;
    }

    public function markComplete(User $user, int $goalId): void
    {
        $goal = $this->findOwnedGoal($user, $goalId);
        if (! $goal instanceof Goal) {
            return;
        }

        $goal->status = GoalStatus::Completed->value;
        $goal->save();

        $this->capture($goal, $user, 'edit', ['status' => $goal->status]);
    }

    public function archive(User $user, int $goalId): void
    {
        $goal = $this->findOwnedGoal($user, $goalId);
        if (! $goal instanceof Goal) {
            return;
        }

        $goal->status = GoalStatus::Archived->value;
        $goal->save();

        $this->capture($goal, $user, 'edit', ['status' => $goal->status]);
    }

    public function restore(User $user, int $goalId): void
    {
        $goal = $this->findOwnedGoal($user, $goalId);
        if (! $goal instanceof Goal) {
            return;
        }

        $goal->status = GoalStatus::Active->value;
        $goal->save();

        $this->capture($goal, $user, 'edit', ['status' => $goal->status]);
    }

    // Every write here goes through one place so a new mutation cannot ship
    // uncaptured — goals were absent from the capture wiring entirely, so a
    // goal created on a phone stayed on that phone forever.
    /**
     * @param  array<string, mixed>  $fields
     */
    private function capture(Goal $goal, User $user, string $mutationType, array $fields): void
    {
        $this->events->dispatch(new GoalMutated(
            goalId: $goal->id,
            userId: $user->id,
            mutationType: $mutationType,
            dirtyFields: $fields,
        ));
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
}
