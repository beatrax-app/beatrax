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

/**
 * The single write path for savings goals.
 *
 * Responsibilities:
 *  - Parse user-entered amounts via `parseAmount()` (proven Dutch + plain
 *    format parser, copied verbatim from BudgetWriter).
 *  - Validate that a client-supplied `account_id` belongs to the user before
 *    saving (IDOR mitigation — T-02-08).
 *  - Create and update goals via an explicit-`$user` `withoutGlobalScope()`
 *    upsert so the authed user is always authoritative (T-02-12).
 *  - Expose the ONLY surface that mutates `status` — the dedicated lifecycle
 *    methods `markComplete()`, `archive()`, and `restore()` — so the field can
 *    never be set from a form argument (T-02-10).
 *
 * INTENTIONAL DIVERGENCE from 02-RESEARCH.md / 02-PATTERNS.md: those artefacts
 * sketched a dependency on the Goals read-model query class in the constructor.
 * This class deliberately does NOT do that — it injects only `DatabaseManager`
 * and runs its own `assertOwnedAccountOrNull()` accounts query inline. This
 * keeps Plans 02-02 and 02-03 independent (parallel-safe) with better
 * decoupling. Do NOT add the read-model query class as a constructor argument.
 */
final class GoalWriter
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Create a new goal for the user.
     *
     * Parses `$rawAmount` into base-currency minor units (rejects invalid /
     * zero / negative input), validates `$accountId` ownership, and writes via
     * a `withoutGlobalScope` upsert with the authed `$user` as authoritative.
     * `status` is hard-coded to 'active'; it is never accepted from the caller.
     *
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

        // Always create a fresh goal — `save()` is the dedicated create path and
        // the lifecycle methods own all subsequent mutation. An `updateOrCreate`
        // keyed on (user_id, name, start_date) would silently overwrite an
        // existing same-name same-day goal (e.g. two "Holiday" goals), which
        // contradicts the create contract (WR-02). Bypass the BelongsToUser
        // global scope so the authed `$user` stays authoritative regardless of
        // guard state (T-02-12).
        /** @var Goal $goal */
        $goal = Goal::query()->withoutGlobalScope(UserScope::class)->create([
            'user_id' => $user->id,
            'name' => $name,
            'start_date' => CarbonImmutable::today()->toDateString(),
            'account_id' => $accountId,
            'target_minor' => $minor,
            'target_currency' => $user->base_currency,
            'target_date' => $targetDate,
            'status' => 'active',
        ]);

        return $goal;
    }

    /**
     * Update name, amount, target date, and linked account for an existing
     * goal owned by `$user`. Resolves the goal through the BelongsToUser-scoped
     * model — a foreign `$goalId` returns null and the call throws.
     *
     * `status` is never touched here (T-02-10; only the lifecycle methods may
     * mutate it).
     *
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
        // Defensively re-assert ownership against the PASSED $user rather than
        // relying solely on the ambient BelongsToUser global scope (which reads
        // CurrentUser and is a no-op in an unauthenticated context). A foreign or
        // missing goalId resolves to null → GoalNotFoundException (WR-04).
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
        // Deliberately NOT touching $goal->status — T-02-10.
        $goal->save();

        return $goal;
    }

    /**
     * Mark a goal as completed. The goal remains retrievable (not deleted).
     * Resolves through the BelongsToUser-scoped model — a foreign id no-ops.
     */
    public function markComplete(User $user, int $goalId): void
    {
        $goal = $this->findOwnedGoal($user, $goalId);
        if (! $goal instanceof Goal) {
            return; // cross-user / missing — silent no-op (T-02-09)
        }

        $goal->status = 'completed';
        $goal->save();
    }

    /**
     * Archive a goal. Hidden from the active list but retained forever and
     * restorable (D-09). Resolves through the BelongsToUser-scoped model —
     * a foreign id no-ops.
     */
    public function archive(User $user, int $goalId): void
    {
        $goal = $this->findOwnedGoal($user, $goalId);
        if (! $goal instanceof Goal) {
            return; // cross-user / missing — silent no-op (T-02-09)
        }

        $goal->status = 'archived';
        $goal->save();
    }

    /**
     * Restore an archived goal to active status. Resolves through the
     * BelongsToUser-scoped model — a foreign id no-ops.
     */
    public function restore(User $user, int $goalId): void
    {
        $goal = $this->findOwnedGoal($user, $goalId);
        if (! $goal instanceof Goal) {
            return; // cross-user / missing — silent no-op (T-02-09)
        }

        $goal->status = 'active';
        $goal->save();
    }

    /**
     * Parse a user-entered amount into positive integer minor units, or null
     * if invalid. Handles the Dutch grouped form "1.234,56" and the plain
     * forms "1234.56" / "50,00" / "50": the rightmost of '.'/',' is the
     * decimal separator and the other is thousands. The whole part is capped
     * at 12 digits so the cents arithmetic cannot overflow PHP_INT_MAX.
     *
     * COPIED VERBATIM from BudgetWriter (proven, unit-tested). Do not
     * re-implement.
     */
    public function parseAmount(string $value): ?int
    {
        $normalised = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($normalised === '') {
            return null;
        }

        $lastDot = strrpos($normalised, '.');
        $lastComma = strrpos($normalised, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $normalised = $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $normalised)
                : str_replace(',', '', $normalised);
        } elseif ($lastComma !== false) {
            $normalised = str_replace(',', '.', $normalised);
        }

        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $normalised) !== 1) {
            return null;
        }

        [$whole, $frac] = array_pad(explode('.', $normalised, 2), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad($frac, 2, '0');

        return $minor > 0 ? $minor : null;
    }

    /**
     * Resolve a goal by id, scoped explicitly to the PASSED `$user` rather than
     * the ambient BelongsToUser global scope. Bypassing the global scope and
     * re-asserting `user_id = $user->id` makes ownership independent of guard
     * state — in an unauthenticated context UserScope applies no filter, so
     * relying on it alone is a latent IDOR (WR-04). Returns null for a missing
     * or cross-user id.
     */
    private function findOwnedGoal(User $user, int $goalId): ?Goal
    {
        /** @var Goal|null $goal */
        $goal = Goal::query()
            ->withoutGlobalScope(UserScope::class)
            ->where('user_id', $user->id)
            ->find($goalId);

        return $goal;
    }

    /**
     * Assert that `$accountId` is either null or an account belonging to
     * `$user`. Throws when the account is non-null and not owned (T-02-08).
     *
     * Runs its own user-scoped accounts query via the injected DatabaseManager
     * so GoalWriter carries no dependency on the Goals read-model query class
     * (intentional decoupling — see class-level docblock).
     *
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
