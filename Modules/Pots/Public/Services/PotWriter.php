<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Exceptions\InsufficientUnallocatedException;
use Modules\Pots\Public\Exceptions\PotNotFoundException;

/**
 * The single write path for savings pots.
 *
 * Responsibilities:
 *  - Parse user-entered amounts via `parseAmount()` (verbatim from GoalWriter).
 *  - Assert that account_id belongs to the user before writing (IDOR — T-03-03).
 *  - Enforce goal-only linking (D-15): category-linked pots are retired by
 *    the envelope-budgeting cutover (Plan 13.2-06) — assertXorLink now
 *    rejects any non-null categoryId outright rather than allowing a
 *    goal-XOR-category choice.
 *  - Enforce one-pot-per-goal (D-11): save/update reject linking a goal that
 *    already has an active linked pot.
 *  - Create and update pots with status hard-coded 'active' (never from caller).
 *  - Append movement rows in transactions so check + insert are atomic (D-06).
 *  - Re-read unallocated INSIDE the transaction for fund/transfer (Pitfall 2 /
 *    T-03-04 over-allocation replay).
 *  - archive() releases balance to unallocated via a final withdraw movement
 *    before status flip (D-09 / Pitfall 4).
 *  - restore() brings pot back empty — no movements inserted (D-09).
 *  - Cross-user / missing pot: findOwnedActivePot returns null → PotNotFoundException
 *    for writes, silent no-op for lifecycle (archive/restore) — mirrors GoalWriter.
 */
final class PotWriter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PotBalanceQuery $balance,
    ) {}

    /**
     * Create a new pot for the user.
     *
     * Validates name, account ownership, XOR link (D-13), one-pot-per-goal
     * (D-11), and optional initial funding (D-08). Currency is set from the
     * account's native currency (D-05).
     *
     * @throws \InvalidArgumentException blank name / unowned account / both goal+category / bad amount
     * @throws InsufficientUnallocatedException when initial funding exceeds unallocated
     */
    public function save(
        User $user,
        string $name,
        ?string $rawInitialAmount,
        int $accountId,
        ?int $goalId,
        ?int $categoryId,
    ): Pot {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Enter a name for this pot.');
        }

        $this->assertOwnedAccount($user, $accountId);
        $this->assertXorLink($goalId, $categoryId);

        if ($goalId !== null) {
            $this->assertGoalOwnedAndFree($user, $goalId);
        }

        $currency = $this->accountCurrency($accountId, $user);

        // Parse the optional initial amount BEFORE any write so an invalid
        // amount string never leaves an orphan pot behind (WR-01).
        $minor = null;
        if ($rawInitialAmount !== null && trim($rawInitialAmount) !== '') {
            $minor = $this->parseAmount($rawInitialAmount);
            if ($minor === null) {
                throw new \InvalidArgumentException('Invalid or non-positive initial amount.');
            }
        }

        // Creation + optional initial funding (D-08) run in ONE transaction so
        // a failed funding check rolls back the pot row — no orphan pot in the
        // list and no duplicate on resubmit (WR-01).
        /** @var Pot $pot */
        $pot = $this->db->connection()->transaction(function () use ($user, $name, $accountId, $goalId, $categoryId, $currency, $minor): Pot {
            /** @var Pot $pot */
            $pot = Pot::query()->withoutGlobalScope(UserScope::class)->create([
                'user_id' => $user->id,
                'account_id' => $accountId,
                'goal_id' => $goalId,
                'category_id' => $categoryId,
                'name' => $name,
                'currency' => $currency,
                'status' => 'active',
            ]);

            if ($minor !== null) {
                $unallocated = $this->balance->currentUnallocatedForAccount($accountId, $user);
                if ($minor > $unallocated) {
                    // Throwing inside the transaction rolls back the pot creation.
                    throw new InsufficientUnallocatedException(
                        'Initial amount exceeds unallocated balance.'
                    );
                }

                $this->db->connection()->table('pot_movements')->insert([
                    'user_id' => $user->id,
                    'pot_id' => $pot->id,
                    'counterpart_pot_id' => null,
                    'amount_minor' => $minor,
                    'currency' => $currency,
                    'kind' => 'fund',
                    'memo' => null,
                    'created_at' => CarbonImmutable::now(),
                    'updated_at' => CarbonImmutable::now(),
                ]);
            }

            return $pot;
        });

        return $pot;
    }

    /**
     * Update name and link (goal XOR category) for an existing active pot.
     * Never touches account_id or status.
     *
     * @throws PotNotFoundException when pot is not found or not owned
     * @throws \InvalidArgumentException blank name / both goal+category
     */
    public function update(
        User $user,
        int $potId,
        string $name,
        ?int $goalId,
        ?int $categoryId,
    ): Pot {
        $pot = $this->findOwnedActivePot($user, $potId);
        if (! $pot instanceof Pot) {
            throw new PotNotFoundException('Pot not found or not owned by user.');
        }

        if (trim($name) === '') {
            throw new \InvalidArgumentException('Enter a name for this pot.');
        }

        $this->assertXorLink($goalId, $categoryId);

        if ($goalId !== null && $goalId !== $pot->goal_id) {
            $this->assertGoalOwnedAndFree($user, $goalId);
        }

        $pot->name = $name;
        $pot->goal_id = $goalId;
        $pot->category_id = $categoryId;
        $pot->save();

        return $pot;
    }

    /**
     * Fund a pot: insert a +amount fund movement (D-07).
     *
     * Re-reads unallocated INSIDE the transaction to prevent over-allocation
     * replay (D-03 / T-03-04 / Pitfall 2).
     *
     * @throws \InvalidArgumentException invalid/zero/negative amount
     * @throws PotNotFoundException pot not found or not owned
     * @throws InsufficientUnallocatedException amount exceeds unallocated
     */
    public function fund(User $user, int $potId, string $rawAmount, ?string $memo = null): void
    {
        $minor = $this->parseAmount($rawAmount);
        if ($minor === null) {
            throw new \InvalidArgumentException('Invalid or non-positive amount.');
        }

        $pot = $this->findOwnedActivePot($user, $potId);
        if (! $pot instanceof Pot) {
            throw new PotNotFoundException('Pot not found or not owned by user.');
        }

        $accountId = $pot->account_id;
        $currency = $pot->currency;

        $this->db->connection()->transaction(function () use ($user, $potId, $minor, $accountId, $currency, $memo): void {
            // Re-read INSIDE transaction to serialise against concurrent writers (Pitfall 2)
            $unallocated = $this->balance->currentUnallocatedForAccount($accountId, $user);
            if ($minor > $unallocated) {
                throw new InsufficientUnallocatedException(
                    'Amount exceeds unallocated balance for this account.'
                );
            }

            $this->db->connection()->table('pot_movements')->insert([
                'user_id' => $user->id,
                'pot_id' => $potId,
                'counterpart_pot_id' => null,
                'amount_minor' => $minor,
                'currency' => $currency,
                'kind' => 'fund',
                'memo' => $memo,
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);
        });
    }

    /**
     * Withdraw from a pot: insert a -amount withdraw movement (D-07).
     *
     * @throws \InvalidArgumentException invalid/zero/negative amount
     * @throws PotNotFoundException pot not found or not owned
     * @throws InsufficientUnallocatedException amount exceeds pot balance
     */
    public function withdraw(User $user, int $potId, string $rawAmount, ?string $memo = null): void
    {
        $minor = $this->parseAmount($rawAmount);
        if ($minor === null) {
            throw new \InvalidArgumentException('Invalid or non-positive amount.');
        }

        $pot = $this->findOwnedActivePot($user, $potId);
        if (! $pot instanceof Pot) {
            throw new PotNotFoundException('Pot not found or not owned by user.');
        }

        $currency = $pot->currency;

        $this->db->connection()->transaction(function () use ($user, $potId, $minor, $currency, $memo): void {
            $potBalance = $this->balance->balanceForPot($potId, $user);
            if ($minor > $potBalance) {
                throw new InsufficientUnallocatedException(
                    'Amount exceeds balance in this pot.'
                );
            }

            $this->db->connection()->table('pot_movements')->insert([
                'user_id' => $user->id,
                'pot_id' => $potId,
                'counterpart_pot_id' => null,
                'amount_minor' => -$minor,
                'currency' => $currency,
                'kind' => 'withdraw',
                'memo' => $memo,
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);
        });
    }

    /**
     * Pot→pot transfer: atomic debit from source + credit to target (D-07).
     *
     * Both pots must be active, owned by the user, and share the same
     * account_id (intra-account moves only — the move modal spec). Checks
     * source balance INSIDE the transaction to prevent over-allocation replay
     * (D-03 / T-03-04 / Pitfall 2).
     *
     * @throws \InvalidArgumentException invalid amount / cross-account / self-transfer
     * @throws PotNotFoundException either pot not found or not owned
     * @throws InsufficientUnallocatedException amount exceeds source pot balance
     */
    public function transfer(
        User $user,
        int $fromPotId,
        int $toPotId,
        string $rawAmount,
        ?string $memo = null,
    ): void {
        $minor = $this->parseAmount($rawAmount);
        if ($minor === null) {
            throw new \InvalidArgumentException('Invalid or non-positive amount.');
        }

        if ($fromPotId === $toPotId) {
            throw new \InvalidArgumentException('Source and target pot must be different.');
        }

        $fromPot = $this->findOwnedActivePot($user, $fromPotId);
        if (! $fromPot instanceof Pot) {
            throw new PotNotFoundException('Source pot not found or not owned by user.');
        }

        $toPot = $this->findOwnedActivePot($user, $toPotId);
        if (! $toPot instanceof Pot) {
            throw new PotNotFoundException('Target pot not found or not owned by user.');
        }

        if ($fromPot->account_id !== $toPot->account_id) {
            throw new \InvalidArgumentException('Transfer is only supported between pots on the same account.');
        }

        $currency = $fromPot->currency;

        $this->db->connection()->transaction(function () use ($user, $fromPotId, $toPotId, $minor, $currency, $memo): void {
            $sourceBalance = $this->balance->balanceForPot($fromPotId, $user);
            if ($minor > $sourceBalance) {
                throw new InsufficientUnallocatedException(
                    'Amount exceeds balance in the source pot.'
                );
            }

            $now = CarbonImmutable::now();

            $this->db->connection()->table('pot_movements')->insert([
                'user_id' => $user->id,
                'pot_id' => $fromPotId,
                'counterpart_pot_id' => $toPotId,
                'amount_minor' => -$minor,
                'currency' => $currency,
                'kind' => 'transfer_out',
                'memo' => $memo,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->db->connection()->table('pot_movements')->insert([
                'user_id' => $user->id,
                'pot_id' => $toPotId,
                'counterpart_pot_id' => $fromPotId,
                'amount_minor' => $minor,
                'currency' => $currency,
                'kind' => 'transfer_in',
                'memo' => $memo,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * Archive a pot: release its balance to unallocated as a final withdraw
     * movement, then set status='archived' — all in one transaction (D-09).
     *
     * A foreign or missing pot id is a silent no-op (mirrors GoalWriter).
     */
    public function archive(User $user, int $potId): void
    {
        $pot = $this->findOwnedActivePot($user, $potId);
        if (! $pot instanceof Pot) {
            return; // cross-user / missing — silent no-op
        }

        $this->db->connection()->transaction(function () use ($user, $pot): void {
            $balance = $this->balance->balanceForPot($pot->id, $user);

            if ($balance > 0) {
                // Release balance back to unallocated before archiving (D-09 / Pitfall 4)
                $this->db->connection()->table('pot_movements')->insert([
                    'user_id' => $user->id,
                    'pot_id' => $pot->id,
                    'counterpart_pot_id' => null,
                    'amount_minor' => -$balance,
                    'currency' => $pot->currency,
                    'kind' => 'withdraw',
                    'memo' => 'Released on archive',
                    'created_at' => CarbonImmutable::now(),
                    'updated_at' => CarbonImmutable::now(),
                ]);
            }

            $pot->status = 'archived';
            $pot->save();
        });
    }

    /**
     * Restore an archived pot to active status. No balance is restored —
     * the pot comes back empty (D-09).
     *
     * One-pot-per-goal (D-11) is re-checked here: if another active pot
     * claimed this pot's goal while it was archived, the restored pot comes
     * back UNLINKED instead of creating a second active pot on the same goal
     * (WR-04).
     *
     * A foreign or missing archived pot id is a silent no-op.
     */
    public function restore(User $user, int $potId): void
    {
        /** @var Pot|null $pot */
        $pot = Pot::query()
            ->withoutGlobalScope(UserScope::class)
            ->where('user_id', $user->id)
            ->where('status', 'archived')
            ->find($potId);

        if (! $pot instanceof Pot) {
            return;
        }

        // D-11 / WR-04: archive() keeps goal_id, and another pot may have
        // been linked to the same goal in the meantime. Restoring must not
        // produce two active pots on one goal — the restored pot loses its
        // link when the goal is already taken.
        if ($pot->goal_id !== null) {
            $goalTaken = $this->db->connection()
                ->table('pots')
                ->where('user_id', $user->id)
                ->where('goal_id', $pot->goal_id)
                ->where('status', 'active')
                ->where('id', '!=', $pot->id)
                ->exists();

            if ($goalTaken) {
                $pot->goal_id = null;
            }
        }

        $pot->status = 'active';
        $pot->save();
    }

    /**
     * Parse a user-entered amount into positive integer minor units, or null
     * if invalid. Handles the Dutch grouped form "1.234,56" and the plain
     * forms "1234.56" / "50,00" / "50": the rightmost of '.'/',' is the
     * decimal separator and the other is thousands. The whole part is capped
     * at 12 digits so the cents arithmetic cannot overflow PHP_INT_MAX.
     *
     * COPIED VERBATIM from GoalWriter (proven, unit-tested). Do not
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

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve an active pot by id, scoped explicitly to the PASSED $user.
     * Bypasses BelongsToUser global scope so ownership is independent of guard
     * state. Returns null for cross-user or missing pot (T-03-03).
     */
    private function findOwnedActivePot(User $user, int $potId): ?Pot
    {
        /** @var Pot|null $pot */
        $pot = Pot::query()
            ->withoutGlobalScope(UserScope::class)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->find($potId);

        return $pot;
    }

    /**
     * Assert that $accountId belongs to $user. Throws when not owned (T-03-03).
     *
     * @throws \InvalidArgumentException
     */
    private function assertOwnedAccount(User $user, int $accountId): void
    {
        $exists = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('id', $accountId)
            ->exists();

        if (! $exists) {
            throw new \InvalidArgumentException('Account not owned by the authenticated user.');
        }
    }

    /**
     * Returns the default_currency of the account — set as the pot's currency
     * at creation time (D-05). Requires assertOwnedAccount to have been called
     * first so $accountId is guaranteed to belong to the user.
     */
    private function accountCurrency(int $accountId, User $user): string
    {
        $row = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('id', $accountId)
            ->first(['default_currency']);

        if ($row === null) {
            return 'EUR';
        }

        return is_string($row->default_currency) ? $row->default_currency : 'EUR';
    }

    /**
     * D-15: category-linked pots are retired by the envelope-budgeting
     * cutover (Plan 06). A pot may only be linked to a goal (or left
     * unlinked) — any non-null `$categoryId` is rejected outright so a stale
     * caller fails loudly instead of silently creating a category link the
     * rest of the system no longer expects (D-13/D-17).
     *
     * @throws \InvalidArgumentException when a category link is attempted
     */
    private function assertXorLink(?int $goalId, ?int $categoryId): void
    {
        if ($categoryId !== null) {
            throw new \InvalidArgumentException(
                'Pots can no longer be linked to a category — link to a goal instead.'
            );
        }
    }

    /**
     * Assert that the goal is owned by the user AND does not already have an
     * active linked pot (D-11 one-pot-per-goal).
     *
     * @throws \InvalidArgumentException when goal not owned or already linked
     */
    private function assertGoalOwnedAndFree(User $user, int $goalId): void
    {
        $goalExists = $this->db->connection()
            ->table('goals')
            ->where('user_id', $user->id)
            ->where('id', $goalId)
            ->exists();

        if (! $goalExists) {
            throw new \InvalidArgumentException('Goal not found or not owned by user.');
        }

        $alreadyLinked = $this->db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('goal_id', $goalId)
            ->where('status', 'active')
            ->exists();

        if ($alreadyLinked) {
            throw new \InvalidArgumentException(
                'This goal already has an active linked pot. Archive it first.'
            );
        }
    }
}
