<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Core\Public\Support\DeviceMintedRowId;
use Modules\Core\Public\Support\SafeDate;
use Modules\Goals\Internal\Exceptions\GoalTargetDateBeforeStartException;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Enums\GoalStatus;
use Modules\Goals\Public\Exceptions\GoalNotFoundException;
use Modules\Goals\Public\Exceptions\InvalidGoalAmountException;
use Modules\Goals\Public\Exceptions\InvalidGoalNameException;
use Modules\Goals\Public\Exceptions\InvalidGoalTargetDateException;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Sync\Public\Events\GoalMutated;

final readonly class GoalWriter
{
    public function __construct(
        private Dispatcher $events,
        private BaseCurrency $baseCurrency,
        private Clock $clock,
    ) {}

    /**
     * @throws InvalidGoalNameException when `$name` is blank.
     * @throws InvalidGoalAmountException when `$rawAmount` is invalid or non-positive.
     * @throws InvalidGoalTargetDateException when `$targetDate` is not a calendar date.
     */
    public function save(
        User $user,
        string $name,
        string $rawAmount,
        string $targetDate,
        ?string $targetCurrency = null,
    ): Goal {
        self::assertNamed($name);

        $currency = $this->resolveTargetCurrency($user, $targetCurrency);

        $minor = $this->parseAmount($rawAmount, $currency);
        if ($minor === null) {
            throw new InvalidGoalAmountException('Invalid or non-positive target amount.');
        }

        // One read of today, used by both the validation and the stored value:
        // taken twice across midnight, the row is checked against one day and
        // written with another. Through the Clock, so a caller seeding a
        // dataset against an injected instant is not validated against another.
        $startDate = $this->clock->now()->startOfDay()->toDateString();
        $targetDate = self::assertRealDate($targetDate, $startDate);

        // Always a fresh row: an updateOrCreate keyed on (user_id, name,
        // start_date) would silently overwrite a second "Holiday" goal made the
        // same day. The global scope is bypassed so $user stays authoritative.
        $attributes = [
            'user_id' => $user->id,
            'name' => $name,
            'start_date' => $startDate,
            'target_minor' => $minor,
            'target_currency' => $currency,
            'target_date' => $targetDate,
            'status' => GoalStatus::Active->value,
        ];

        // The id is minted, not taken from the autoincrement: two devices used
        // while apart both take the next one, and goals declares no unique
        // index to tell the two rows apart afterwards. Not derived either, for
        // the reason the row is always fresh — two goals of one name are two.
        $goal = Goal::query()->withoutGlobalScope(UserScope::class)
            ->forceCreate(['id' => DeviceMintedRowId::mint(), ...$attributes]);

        $this->capture($goal, $user, 'create', $attributes);

        return $goal;
    }

    /**
     * @throws GoalNotFoundException when the goal is not found (cross-user / missing).
     * @throws InvalidGoalNameException when `$name` is blank.
     * @throws InvalidGoalAmountException when `$rawAmount` is invalid or non-positive.
     */
    public function update(
        User $user,
        int $goalId,
        string $name,
        string $rawAmount,
        string $targetDate,
    ): Goal {
        self::assertNamed($name);

        $goal = $this->findOwnedGoal($user, $goalId);
        if (! $goal instanceof Goal) {
            throw new GoalNotFoundException('Goal not found or not owned by user.');
        }

        // The goal's own currency, never the reader's current base one: the
        // column is fixed at creation and an edit must land at its scale.
        $minor = $this->parseAmount($rawAmount, $goal->target_currency);
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

    // The rule the form has always applied, moved into the writer beside the
    // amount and the date: a migration reached save() straight past the form
    // and created a nameless goal the form then refused to re-save.
    /**
     * @throws InvalidGoalNameException
     */
    private static function assertNamed(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidGoalNameException('Enter a name for this goal.');
        }
    }

    // Two refusals, not one: a string that is no calendar date at all, and a
    // real date the goal starts after. The column took whatever the form sent,
    // so the projection, the card and the sort all worked from a date the
    // owner never chose.
    private static function assertRealDate(string $targetDate, string $startDate): string
    {
        if (SafeDate::dayOrNull($targetDate) === null) {
            throw new InvalidGoalTargetDateException('Target date is not a calendar date.');
        }

        if ($targetDate < $startDate) {
            throw new GoalTargetDateBeforeStartException('Target date is before the goal starts.');
        }

        return $targetDate;
    }

    public function parseAmount(string $value, ?string $currencyCode = null): ?int
    {
        return MoneyInput::tryToPositiveMinor($value, $currencyCode);
    }

    // A migrated goal carries the source file's own currency: keeping the
    // number and dropping the code turned a $10,000 target into a €10,000 one.
    // The reader's base currency is the fallback for a caller with no currency
    // to offer, and for a code no currency table knows.
    private function resolveTargetCurrency(User $user, ?string $requested): string
    {
        if ($requested !== null && Money::tryOfMinor(0, $requested) !== null) {
            return $requested;
        }

        return $this->baseCurrency->forUser($user);
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
