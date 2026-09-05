<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Core\Public\Support\DeviceMintedRowId;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Pots\Internal\Exceptions\AccountCannotHoldPotsException;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Enums\PotMovementKind;
use Modules\Pots\Public\Enums\PotStatus;
use Modules\Pots\Public\Exceptions\CrossAccountTransferException;
use Modules\Pots\Public\Exceptions\GoalAlreadyLinkedException;
use Modules\Pots\Public\Exceptions\InsufficientUnallocatedException;
use Modules\Pots\Public\Exceptions\InvalidPotAmountException;
use Modules\Pots\Public\Exceptions\PotAlreadyLinkedException;
use Modules\Pots\Public\Exceptions\PotLinkedToCategoryException;
use Modules\Pots\Public\Exceptions\PotNotFoundException;
use Modules\Pots\Public\Exceptions\SelfTransferException;
use Modules\Pots\Public\Exceptions\TargetPotNotFoundException;
use Modules\Sync\Public\Events\EntityMutated;

final readonly class PotWriter
{
    private const string NOT_FOUND_MESSAGE = 'Pot not found or not owned by user.';

    private const string INVALID_AMOUNT_MESSAGE = 'Invalid or non-positive amount.';

    public function __construct(
        private DatabaseManager $db,
        private PotBalanceQuery $balance,
        private Dispatcher $events,
    ) {}

    /**
     * @throws \InvalidArgumentException blank name / unowned account / both goal+category
     * @throws InvalidPotAmountException when the initial amount does not read as a positive figure
     * @throws AccountCannotHoldPotsException when the account holds no allocatable balance
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

        $currency = $this->balance->currencyForAccount($accountId, $user);

        // Parse the optional initial amount before any write so an invalid
        // amount string never leaves an orphan pot behind.
        $minor = null;
        if ($rawInitialAmount !== null && trim($rawInitialAmount) !== '') {
            $minor = $this->parseAmount($rawInitialAmount, $currency);
            if ($minor === null) {
                throw new InvalidPotAmountException('Invalid or non-positive initial amount.');
            }
        }

        /** @var list<EntityMutated> $events */
        $events = [];

        // One transaction so a failed funding check rolls back the pot row:
        // no orphan pot in the list, no duplicate on resubmit.
        /** @var Pot $pot */
        $pot = $this->db->connection()->transaction(function () use ($user, $name, $accountId, $goalId, $categoryId, $currency, $minor, &$events): Pot {
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
                $unallocated = $this->balance->currentUnallocatedForAccount($accountId, $user, $currency);
                if ($minor > $unallocated) {
                    throw new InsufficientUnallocatedException(
                        'Initial amount exceeds unallocated balance.'
                    );
                }

                $events[] = $this->insertMovement([
                    'user_id' => $user->id,
                    'pot_id' => $pot->id,
                    'counterpart_pot_id' => null,
                    'amount_minor' => $minor,
                    'currency' => $currency,
                    'kind' => PotMovementKind::Fund->value,
                    'memo' => null,
                ]);
            }

            return $pot;
        });

        $this->dispatchAll([$this->capture($pot, 'create', [
            'user_id' => $user->id,
            'account_id' => $accountId,
            'goal_id' => $goalId,
            'category_id' => $categoryId,
            'name' => $name,
            'currency' => $currency,
            'status' => $pot->status,
        ]), ...$events]);

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

        $this->dispatchAll([$this->capture($pot, 'edit', [
            'name' => $name,
            'goal_id' => $goalId,
            'category_id' => $categoryId,
        ])]);

        return $pot;
    }

    // The goal link on its own, so a relink never has to send the pot's other
    // columns back through update(): re-reading a name to rewrite it loses a
    // concurrent rename, and a name that read back blank refused the link with
    // "Enter a name for this pot."
    /**
     * @throws PotNotFoundException pot not found, not owned, or not active
     * @throws GoalAlreadyLinkedException another active pot already holds this goal
     * @throws PotAlreadyLinkedException this pot already holds another goal
     * @throws PotLinkedToCategoryException the pot's category link is the one thing a goal link would destroy
     */
    public function linkGoal(User $user, int $potId, ?int $goalId): void
    {
        $pot = $this->findOwnedActivePot($user, $potId);
        if (! $pot instanceof Pot) {
            throw new PotNotFoundException(self::NOT_FOUND_MESSAGE);
        }

        if ($pot->goal_id === $goalId) {
            return;
        }

        if ($goalId !== null) {
            if ($pot->category_id !== null) {
                throw new PotLinkedToCategoryException(
                    'This pot still carries a retired category link; re-saving it on the Pots page clears it.'
                );
            }

            // Both directions, not just the goal's. Only the goal side was
            // checked, so a goal write could take a pot another goal already
            // held: the pot moved and the first goal read 0% with no pot.
            if ($pot->goal_id !== null) {
                throw new PotAlreadyLinkedException(
                    'This pot already funds another goal. Unlink it there first.'
                );
            }

            $this->assertGoalOwnedAndFree($user, $goalId);
        }

        $pot->goal_id = $goalId;
        $pot->save();

        $this->dispatchAll([$this->capture($pot, 'edit', ['goal_id' => $goalId])]);
    }

    /**
     * @throws \InvalidArgumentException invalid/zero/negative amount
     * @throws PotNotFoundException pot not found or not owned
     * @throws InsufficientUnallocatedException amount exceeds unallocated
     */
    public function fund(User $user, int $potId, string $rawAmount, ?string $memo = null): void
    {
        $pot = $this->findOwnedActivePot($user, $potId);
        if (! $pot instanceof Pot) {
            throw new PotNotFoundException(self::NOT_FOUND_MESSAGE);
        }

        $accountId = $pot->account_id;
        $currency = $pot->currency;

        // The pot is found before the figure is read, because the pot's own
        // denomination is what decides the scale: read at a hundredth, ¥13,840
        // was refused outright and ¥120,000 was weighed as ¥12,000,000.
        $minor = $this->parseAmount($rawAmount, $currency);
        if ($minor === null) {
            throw new InvalidPotAmountException(self::INVALID_AMOUNT_MESSAGE);
        }

        /** @var list<EntityMutated> $events */
        $events = [];

        $this->db->connection()->transaction(function () use ($user, $potId, $minor, $accountId, $currency, $memo, &$events): void {
            // Re-read inside the transaction, and against the pot's own
            // currency rather than the account's: a relabelled account weighed
            // a euro pot against a yen line and refused every fund the euros
            // could cover.
            $unallocated = $this->balance->currentUnallocatedForAccount($accountId, $user, $currency);
            if ($minor > $unallocated) {
                throw new InsufficientUnallocatedException(
                    'Amount exceeds unallocated balance for this account.'
                );
            }

            $events[] = $this->insertMovement([
                'user_id' => $user->id,
                'pot_id' => $potId,
                'counterpart_pot_id' => null,
                'amount_minor' => $minor,
                'currency' => $currency,
                'kind' => PotMovementKind::Fund->value,
                'memo' => $memo,
            ]);
        });

        $this->dispatchAll($events);
    }

    /**
     * @throws \InvalidArgumentException invalid/zero/negative amount
     * @throws PotNotFoundException pot not found or not owned
     * @throws InsufficientUnallocatedException amount exceeds pot balance
     */
    public function withdraw(User $user, int $potId, string $rawAmount, ?string $memo = null): void
    {
        $pot = $this->findOwnedActivePot($user, $potId);
        if (! $pot instanceof Pot) {
            throw new PotNotFoundException(self::NOT_FOUND_MESSAGE);
        }

        $currency = $pot->currency;

        $minor = $this->parseAmount($rawAmount, $currency);
        if ($minor === null) {
            throw new InvalidPotAmountException(self::INVALID_AMOUNT_MESSAGE);
        }

        /** @var list<EntityMutated> $events */
        $events = [];

        $this->db->connection()->transaction(function () use ($user, $potId, $minor, $currency, $memo, &$events): void {
            $potBalance = $this->balance->balanceForPot($potId, $user);
            if ($minor > $potBalance) {
                throw new InsufficientUnallocatedException(
                    'Amount exceeds balance in this pot.'
                );
            }

            $events[] = $this->insertMovement([
                'user_id' => $user->id,
                'pot_id' => $potId,
                'counterpart_pot_id' => null,
                'amount_minor' => -$minor,
                'currency' => $currency,
                'kind' => PotMovementKind::Withdraw->value,
                'memo' => $memo,
            ]);
        });

        $this->dispatchAll($events);
    }

    /**
     * @throws InvalidPotAmountException the amount does not read as a positive figure
     * @throws PotNotFoundException the source pot is not found or not owned
     * @throws SelfTransferException source and target are the same pot
     * @throws TargetPotNotFoundException the target pot is not found or not owned
     * @throws CrossAccountTransferException the two pots sit on different accounts
     * @throws InsufficientUnallocatedException amount exceeds source pot balance
     */
    public function transfer(
        User $user,
        int $fromPotId,
        int $toPotId,
        string $rawAmount,
        ?string $memo = null,
    ): void {
        $fromPot = $this->findOwnedActivePot($user, $fromPotId);
        if (! $fromPot instanceof Pot) {
            throw new PotNotFoundException('Source pot not found or not owned by user.');
        }

        $currency = $fromPot->currency;

        // Ahead of the same-pot rule, which the amount still outranks, but
        // behind the source pot: the pot's denomination is what the figure is
        // read at, and a yen one has no hundredth to read it in.
        $minor = $this->parseAmount($rawAmount, $currency);
        if ($minor === null) {
            throw new InvalidPotAmountException(self::INVALID_AMOUNT_MESSAGE);
        }

        if ($fromPotId === $toPotId) {
            throw new SelfTransferException('Source and target pot must be different.');
        }

        $toPot = $this->findOwnedActivePot($user, $toPotId);
        if (! $toPot instanceof Pot) {
            throw new TargetPotNotFoundException('Target pot not found or not owned by user.');
        }

        if ($fromPot->account_id !== $toPot->account_id) {
            throw new CrossAccountTransferException('Transfer is only supported between pots on the same account.');
        }

        /** @var list<EntityMutated> $events */
        $events = [];

        $this->db->connection()->transaction(function () use ($user, $fromPotId, $toPotId, $minor, $currency, $memo, &$events): void {
            $sourceBalance = $this->balance->balanceForPot($fromPotId, $user);
            if ($minor > $sourceBalance) {
                throw new InsufficientUnallocatedException(
                    'Amount exceeds balance in the source pot.'
                );
            }

            $now = CarbonImmutable::now()->toDateTimeString();

            $events[] = $this->insertMovement([
                'user_id' => $user->id,
                'pot_id' => $fromPotId,
                'counterpart_pot_id' => $toPotId,
                'amount_minor' => -$minor,
                'currency' => $currency,
                'kind' => PotMovementKind::TransferOut->value,
                'memo' => $memo,
            ], $now);

            $events[] = $this->insertMovement([
                'user_id' => $user->id,
                'pot_id' => $toPotId,
                'counterpart_pot_id' => $fromPotId,
                'amount_minor' => $minor,
                'currency' => $currency,
                'kind' => PotMovementKind::TransferIn->value,
                'memo' => $memo,
            ], $now);
        });

        $this->dispatchAll($events);
    }

    public function archive(User $user, int $potId): void
    {
        $pot = $this->findOwnedActivePot($user, $potId);
        if (! $pot instanceof Pot) {
            return;
        }

        /** @var list<EntityMutated> $events */
        $events = [];

        $this->db->connection()->transaction(function () use ($user, $pot, &$events): void {
            $balance = $this->balance->balanceForPot($pot->id, $user);

            if ($balance > 0) {
                $events[] = $this->insertMovement([
                    'user_id' => $user->id,
                    'pot_id' => $pot->id,
                    'counterpart_pot_id' => null,
                    'amount_minor' => -$balance,
                    'currency' => $pot->currency,
                    'kind' => PotMovementKind::ReleasedOnArchive->value,
                    'memo' => null,
                ]);
            }

            $pot->status = PotStatus::Archived->value;
            $pot->save();

            $events[] = $this->capture($pot, 'edit', ['status' => $pot->status]);
        });

        $this->dispatchAll($events);
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

        // Only what this restore actually rewrites. Announcing a column it left
        // alone hands the peer a fresh timestamp for a value it may hold a
        // newer one of, and the two links below are cleared conditionally.
        $changed = ['status' => PotStatus::Active->value];

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
                $changed['goal_id'] = null;
            }
        }

        // The category link is retired: no write makes one, and the only way out
        // is an edit and save on the Pots page. A pot restored still carrying a
        // legacy link came back in a shape nothing else can produce — active,
        // category-linked, and refused as a goal target on the strength of it.
        if ($pot->category_id !== null) {
            $pot->category_id = null;
            $changed['category_id'] = null;
        }

        $pot->status = PotStatus::Active->value;
        $pot->save();

        $this->dispatchAll([$this->capture($pot, 'edit', $changed)]);
    }

    // The pot's own denomination, never the repo-wide hundredth: a pot on a
    // yen account holds whole yen, and nothing here can tell the two apart
    // without being told which one it is.
    public function parseAmount(string $value, ?string $currencyCode = null): ?int
    {
        return MoneyInput::tryToPositiveMinor($value, $currencyCode);
    }

    // Movements had merge rules but no capture, so a fund or withdraw made after
    // pairing never left the device and the peer's balances froze at the backfill.
    // Sole insert path, so a new movement kind cannot ship uncaptured.
    /**
     * @param  array{user_id: int}&array<string, mixed>  $row  Every column but the timestamps.
     */
    private function insertMovement(array $row, ?string $now = null): EntityMutated
    {
        $stamp = $now ?? CarbonImmutable::now()->toDateTimeString();
        $row['created_at'] = $stamp;
        $row['updated_at'] = $stamp;

        // Not the autoincrement: two devices used while apart both take the
        // next one, and pot_movements has no unique index to tell the two
        // rows apart afterwards. Minted rather than derived — a second
        // deposit of the same amount on the same day is a second deposit.
        $id = DeviceMintedRowId::mint();

        $this->db->connection()->table('pot_movements')->insert(['id' => $id] + $row);

        return new EntityMutated(
            table: 'pot_movements',
            pk: $id,
            userId: $row['user_id'],
            mutationType: 'create',
            dirtyFields: $row,
        );
    }

    // Pots were absent from the capture wiring, so a pot created or renamed on one
    // device never reached the other. Sole path, so a new write cannot ship uncaptured.
    /**
     * @param  array<string, mixed>  $fields
     */
    private function capture(Pot $pot, string $mutationType, array $fields): EntityMutated
    {
        return new EntityMutated(
            table: 'pots',
            pk: $pot->id,
            userId: (int) $pot->user_id,
            mutationType: $mutationType,
            dirtyFields: $fields,
        );
    }

    // Every write path in this class ends here, and every one of them ends here
    // AFTER its transaction has committed. A listener that reads the row back
    // from inside an open transaction sees a state no other connection has.
    /**
     * @param  list<EntityMutated>  $events
     */
    private function dispatchAll(array $events): void
    {
        foreach ($events as $event) {
            $this->events->dispatch($event);
        }
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

    // The account picker only ever offered an allocatable account, and the id it
    // sends is the client's. A pot on a credit card is over-allocated the moment
    // it exists and can never be funded, so the kind is re-asserted here.
    /**
     * @throws \InvalidArgumentException when the account is not the user's
     * @throws AccountCannotHoldPotsException when the account holds no allocatable balance
     */
    private function assertOwnedAccount(User $user, int $accountId): void
    {
        $row = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('id', $accountId)
            ->first(['kind']);

        if ($row === null) {
            throw new \InvalidArgumentException('Account not owned by the authenticated user.');
        }

        $kind = is_string($row->kind) ? AccountKind::tryFrom($row->kind) : null;

        if ($kind?->holdsSpendableBalance() !== true) {
            throw new AccountCannotHoldPotsException('This account holds no balance a pot can carve up.');
        }
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
            throw new GoalAlreadyLinkedException(
                'This goal already has an active linked pot. Archive it first.'
            );
        }
    }
}
