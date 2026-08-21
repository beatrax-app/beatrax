<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Enums\PotStatus;
use Modules\Pots\Public\Exceptions\InsufficientUnallocatedException;
use Modules\Pots\Public\Exceptions\PotNotFoundException;
use Modules\Sync\Public\Events\EntityMutated;

final class PotWriter
{
    private const NOT_FOUND_MESSAGE = 'Pot not found or not owned by user.';

    private const INVALID_AMOUNT_MESSAGE = 'Invalid or non-positive amount.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PotBalanceQuery $balance,
        private readonly BaseCurrency $baseCurrency,
        private readonly Dispatcher $events,
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
        $this->assertXorLink($categoryId);

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

        // One transaction so a failed funding check rolls back the pot row:
        // no orphan pot in the list, no duplicate on resubmit.
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
                'status' => PotStatus::Active->value,
            ]);

            if ($minor !== null) {
                $unallocated = $this->balance->currentUnallocatedForAccount($accountId, $user);
                if ($minor > $unallocated) {
                    throw new InsufficientUnallocatedException(
                        'Initial amount exceeds unallocated balance.'
                    );
                }

                $this->insertMovement([
                    'user_id' => $user->id,
                    'pot_id' => $pot->id,
                    'counterpart_pot_id' => null,
                    'amount_minor' => $minor,
                    'currency' => $currency,
                    'kind' => 'fund',
                    'memo' => null,
                ]);
            }

            return $pot;
        });

        $this->capture($pot, 'create', [
            'user_id' => $user->id,
            'account_id' => $accountId,
            'goal_id' => $goalId,
            'category_id' => $categoryId,
            'name' => $name,
            'currency' => $currency,
            'status' => $pot->status,
        ]);

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

        $this->assertXorLink($categoryId);

        if ($goalId !== null && $goalId !== $pot->goal_id) {
            $this->assertGoalOwnedAndFree($user, $goalId);
        }

        $pot->name = $name;
        $pot->goal_id = $goalId;
        $pot->category_id = $categoryId;
        $pot->save();

        $this->capture($pot, 'edit', [
            'name' => $name,
            'goal_id' => $goalId,
            'category_id' => $categoryId,
        ]);

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
            // Re-read inside the transaction; a concurrent writer makes the
            // pre-transaction value stale.
            $unallocated = $this->balance->currentUnallocatedForAccount($accountId, $user);
            if ($minor > $unallocated) {
                throw new InsufficientUnallocatedException(
                    'Amount exceeds unallocated balance for this account.'
                );
            }

            $this->insertMovement([
                'user_id' => $user->id,
                'pot_id' => $potId,
                'counterpart_pot_id' => null,
                'amount_minor' => $minor,
                'currency' => $currency,
                'kind' => 'fund',
                'memo' => $memo,
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

            $this->insertMovement([
                'user_id' => $user->id,
                'pot_id' => $potId,
                'counterpart_pot_id' => null,
                'amount_minor' => -$minor,
                'currency' => $currency,
                'kind' => 'withdraw',
                'memo' => $memo,
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

            $now = CarbonImmutable::now()->toDateTimeString();

            $this->insertMovement([
                'user_id' => $user->id,
                'pot_id' => $fromPotId,
                'counterpart_pot_id' => $toPotId,
                'amount_minor' => -$minor,
                'currency' => $currency,
                'kind' => 'transfer_out',
                'memo' => $memo,
            ], $now);

            $this->insertMovement([
                'user_id' => $user->id,
                'pot_id' => $toPotId,
                'counterpart_pot_id' => $fromPotId,
                'amount_minor' => $minor,
                'currency' => $currency,
                'kind' => 'transfer_in',
                'memo' => $memo,
            ], $now);
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
                $this->insertMovement([
                    'user_id' => $user->id,
                    'pot_id' => $pot->id,
                    'counterpart_pot_id' => null,
                    'amount_minor' => -$balance,
                    'currency' => $pot->currency,
                    'kind' => 'withdraw',
                    'memo' => 'Released on archive',
                ]);
            }

            $pot->status = PotStatus::Archived->value;
            $pot->save();

            $this->capture($pot, 'edit', ['status' => $pot->status]);
        });
    }

    public function restore(User $user, int $potId): void
    {
        /** @var Pot|null $pot */
        $pot = Pot::query()
            ->withoutGlobalScope(UserScope::class)
            ->where('user_id', $user->id)
            ->where('status', PotStatus::Archived->value)
            ->find($potId);

        if (! $pot instanceof Pot) {
            return;
        }

        // archive() keeps goal_id, so another pot may have claimed the goal
        // meanwhile; restoring must not leave two active pots on one goal.
        if ($pot->goal_id !== null) {
            $goalTaken = $this->db->connection()
                ->table('pots')
                ->where('user_id', $user->id)
                ->where('goal_id', $pot->goal_id)
                ->where('status', PotStatus::Active->value)
                ->where('id', '!=', $pot->id)
                ->exists();

            if ($goalTaken) {
                $pot->goal_id = null;
            }
        }

        $pot->status = PotStatus::Active->value;
        $pot->save();

        $this->capture($pot, 'edit', ['status' => $pot->status]);
    }

    public function parseAmount(string $value): ?int
    {
        return MoneyInput::tryToPositiveMinor($value);
    }

    // Movements had merge rules but no capture, so a fund or withdraw made after
    // pairing never left the device and the peer's balances froze at the backfill.
    // Sole insert path, so a new movement kind cannot ship uncaptured.
    /**
     * @param  array{user_id: int}&array<string, mixed>  $row  Every column but the timestamps.
     */
    private function insertMovement(array $row, ?string $now = null): void
    {
        $stamp = $now ?? CarbonImmutable::now()->toDateTimeString();
        $row['created_at'] = $stamp;
        $row['updated_at'] = $stamp;

        $id = $this->db->connection()->table('pot_movements')->insertGetId($row);

        $this->events->dispatch(new EntityMutated(
            table: 'pot_movements',
            pk: $id,
            userId: $row['user_id'],
            mutationType: 'create',
            dirtyFields: $row,
        ));
    }

    // Pots were absent from the capture wiring, so a pot created or renamed on one
    // device never reached the other. Sole path, so a new write cannot ship uncaptured.
    /**
     * @param  array<string, mixed>  $fields
     */
    private function capture(Pot $pot, string $mutationType, array $fields): void
    {
        $this->events->dispatch(new EntityMutated(
            table: 'pots',
            pk: $pot->id,
            userId: (int) $pot->user_id,
            mutationType: $mutationType,
            dirtyFields: $fields,
        ));
    }

    // The global user scope is dropped deliberately: ownership comes from the
    // explicit user_id filter, so this holds even when no guard is resolved.
    private function findOwnedActivePot(User $user, int $potId): ?Pot
    {
        return Pot::query()
            ->withoutGlobalScope(UserScope::class)
            ->where('user_id', $user->id)
            ->where('status', PotStatus::Active->value)
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

    private function accountCurrency(int $accountId, User $user): string
    {
        $row = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('id', $accountId)
            ->first(['default_currency']);

        return ($row !== null && is_string($row->default_currency))
            ? $row->default_currency
            : $this->baseCurrency->code();
    }

    /**
     * @throws \InvalidArgumentException when a category link is attempted
     */
    private function assertXorLink(?int $categoryId): void
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
            ->where('status', PotStatus::Active->value)
            ->exists();

        if ($alreadyLinked) {
            throw new \InvalidArgumentException(
                'This goal already has an active linked pot. Archive it first.'
            );
        }
    }
}
