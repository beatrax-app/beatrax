<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Enums\GoalStatus;
use Modules\Goals\Public\Exceptions\GoalNotFoundException;
use Modules\Goals\Public\Exceptions\InvalidGoalAmountException;
use Modules\Goals\Public\Exceptions\InvalidGoalTargetDateException;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Sync\Public\Events\GoalMutated;

final class GoalWriter
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly BaseCurrency $baseCurrency,
    ) {}

    /**
     * @throws InvalidGoalAmountException when `$rawAmount` is invalid or non-positive.
     * @throws InvalidGoalTargetDateException when `$targetDate` is not a calendar date.
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

        $startDate = CarbonImmutable::today()->toDateString();
        $targetDate = self::assertRealDate($targetDate, $startDate);

        // Always a fresh row: an updateOrCreate keyed on (user_id, name,
        // start_date) would silently overwrite a second "Holiday" goal made the
        // same day. The global scope is bypassed so $user stays authoritative.
        $attributes = [
            'user_id' => $user->id,
            'name' => $name,
            'start_date' => CarbonImmutable::today()->toDateString(),
            'target_minor' => $minor,
            'target_currency' => $this->baseCurrency->forUser($user),
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

        $targetDate = self::assertRealDate($targetDate, $goal->start_date->toDateString());

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

    // Goals were absent from the capture wiring, so a goal created on a phone
    // stayed there forever. Sole path, so a new write cannot ship uncaptured.
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

    public const DATE_FORMAT = 'Y-m-d';

    // Carbon accepts "2026-02-30" and normalises it to "2026-03-02", so a
    // round-trip format comparison is the real validity check -- the same one
    // PeriodQuery::containingDate makes. The column took whatever the form
    // sent, and the projection, the card and the sort then all worked from a
    // date the goal's owner never chose.
    private static function assertRealDate(string $targetDate, string $startDate): string
    {
        // createFromFormat throws on a string it cannot read at all rather
        // than returning null, so both outcomes have to be caught here.
        try {
            $parsed = CarbonImmutable::createFromFormat(self::DATE_FORMAT, $targetDate);
        } catch (InvalidFormatException) {
            $parsed = null;
        }

        if ($parsed === null || $parsed->format(self::DATE_FORMAT) !== $targetDate) {
            throw new InvalidGoalTargetDateException('Target date is not a calendar date.');
        }

        if ($targetDate < $startDate) {
            throw new InvalidGoalTargetDateException('Target date is before the goal starts.');
        }

        return $targetDate;
    }

    public function parseAmount(string $value): ?int
    {
        return MoneyInput::tryToPositiveMinor($value);
    }

    // The ambient scope is a no-op outside an authenticated context, so leaning
    // on it alone would be a latent IDOR: user_id is re-asserted explicitly.
    private function findOwnedGoal(User $user, int $goalId): ?Goal
    {
        return Goal::query()
            ->withoutGlobalScope(UserScope::class)
            ->where('user_id', $user->id)
            ->find($goalId);
    }
}
