<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Exceptions\InsufficientUnallocatedException;
use Modules\Pots\Public\Exceptions\PotNotFoundException;

/**
 * @link ../../../../.docs/features/pots/architecture.md
 */
final class PotWriter
{
    private const NOT_FOUND_MESSAGE = 'Pot not found or not owned by user.';

    private const INVALID_AMOUNT_MESSAGE = 'Invalid or non-positive amount.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PotBalanceQuery $balance,
    ) {}

    /**
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

        // Parse the optional initial amount before any write so an invalid
        // amount string never leaves an orphan pot behind.
        $minor = null;
        if ($rawInitialAmount !== null && trim($rawInitialAmount) !== '') {
            $minor = $this->parseAmount($rawInitialAmount);
            if ($minor === null) {
                throw new \InvalidArgumentException('Invalid or non-positive initial amount.');
            }
        }

        // Creation + optional initial funding run in one transaction so a
        // failed funding check rolls back the pot row — no orphan pot in
        // the list and no duplicate on resubmit.
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
            throw new PotNotFoundException(self::NOT_FOUND_MESSAGE);
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
     * @throws \InvalidArgumentException invalid/zero/negative amount
     * @throws PotNotFoundException pot not found or not owned
     * @throws InsufficientUnallocatedException amount exceeds unallocated
     */
    public function fund(User $user, int $potId, string $rawAmount, ?string $memo = null): void
    {
        $minor = $this->parseAmount($rawAmount);
        if ($minor === null) {
            throw new \InvalidArgumentException(self::INVALID_AMOUNT_MESSAGE);
        }

        $pot = $this->findOwnedActivePot($user, $potId);
        if (! $pot instanceof Pot) {
            throw new PotNotFoundException(self::NOT_FOUND_MESSAGE);
        }

        $accountId = $pot->account_id;
        $currency = $pot->currency;

        $this->db->connection()->transaction(function () use ($user, $potId, $minor, $accountId, $currency, $memo): void {
            // Re-read inside the transaction to serialise against concurrent
            // writers rather than checking against a stale value.
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
     * @throws \InvalidArgumentException invalid/zero/negative amount
     * @throws PotNotFoundException pot not found or not owned
     * @throws InsufficientUnallocatedException amount exceeds pot balance
     */
    public function withdraw(User $user, int $potId, string $rawAmount, ?string $memo = null): void
    {
        $minor = $this->parseAmount($rawAmount);
        if ($minor === null) {
            throw new \InvalidArgumentException(self::INVALID_AMOUNT_MESSAGE);
        }

        $pot = $this->findOwnedActivePot($user, $potId);
        if (! $pot instanceof Pot) {
            throw new PotNotFoundException(self::NOT_FOUND_MESSAGE);
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
            throw new \InvalidArgumentException(self::INVALID_AMOUNT_MESSAGE);
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

    public function archive(User $user, int $potId): void
    {
        $pot = $this->findOwnedActivePot($user, $potId);
        if (! $pot instanceof Pot) {
            return;
        }

        $this->db->connection()->transaction(function () use ($user, $pot): void {
            $balance = $this->balance->balanceForPot($pot->id, $user);

            if ($balance > 0) {
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

        // archive() keeps goal_id, and another pot may have been linked to
        // the same goal in the meantime — restoring must not produce two
        // active pots on one goal.
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

    // Parses a user-entered positive amount to integer minor units — the
    // shared MoneyInput handles the Dutch/plain decimal forms — or null for a
    // blank, malformed, zero or negative entry.
    public function parseAmount(string $value): ?int
    {
        return MoneyInput::tryToPositiveMinor($value);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    // Bypasses the global scope so ownership is independent of guard state.
    // Returns null for a cross-user or missing pot.
    private function findOwnedActivePot(User $user, int $potId): ?Pot
    {
        return Pot::query()
            ->withoutGlobalScope(UserScope::class)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->find($potId);
    }

    /**
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

    // Requires assertOwnedAccount to have been called first so $accountId is
    // guaranteed to belong to the user.
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
