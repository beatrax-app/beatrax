<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Events\TransactionMutated;

/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
final class ReconciliationWriter
{
    use CoercesScalars;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @return int the number of rows actually transitioned to `reconciled`
     *             — 0 when nothing fell in the statement-date window, so
     *             callers can report the truthful outcome.
     *
     * @throws InvalidArgumentException when `$accountId` is not owned by `$user`.
     */
    public function completeReconcile(User $user, int $accountId, CarbonImmutable $statementDate): int
    {
        $this->assertOwnedAccount($user, $accountId);

        $connection = $this->db->connection();
        $statementDateString = $statementDate->toDateString();
        $reconciledAt = $this->clock->now();

        // Captures the transitioned id set as an explicit SELECT before
        // the UPDATE, inside the same transaction — see the linked
        // architecture page for why this avoids inflated counts and
        // duplicate dispatches under concurrent calls.
        $transactionIds = [];

        $connection->transaction(function () use ($connection, $accountId, $user, $statementDateString, $reconciledAt, &$transactionIds): void {
            $candidateIds = $connection->table('transactions')
                ->where('account_id', $accountId)
                ->where('user_id', $user->id)
                ->where('status', 'cleared')
                ->where('posted_at', '<=', $statementDateString)
                ->pluck('id')
                ->map(static fn (mixed $id): int => self::toInt($id))
                ->all();

            if ($candidateIds === []) {
                return;
            }

            $affected = $connection->table('transactions')
                ->whereIn('id', $candidateIds)
                ->where('user_id', $user->id)
                ->where('status', 'cleared')
                ->update([
                    'status' => 'reconciled',
                    'updated_at' => $reconciledAt,
                ]);

            if ($affected === count($candidateIds)) {
                $transactionIds = $candidateIds;

                return;
            }

            $transactionIds = $connection->table('transactions')
                ->whereIn('id', $candidateIds)
                ->where('status', 'reconciled')
                ->where('updated_at', $reconciledAt)
                ->pluck('id')
                ->map(static fn (mixed $id): int => self::toInt($id))
                ->all();
        });

        // Dispatched after commit — one per actually-transitioned row,
        // never a single synthetic bulk event.
        foreach ($transactionIds as $transactionId) {
            $this->events->dispatch(new TransactionMutated(
                transactionId: $transactionId,
                userId: $user->id,
                mutationType: 'edit',
                dirtyFields: ['status' => 'reconciled'],
            ));
        }

        return count($transactionIds);
    }

    // A foreign or missing transaction id, or one not currently
    // reconciled, is a silent no-op.
    public function unreconcile(User $user, int $transactionId): void
    {
        $connection = $this->db->connection();

        $row = $connection->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first(['id', 'status']);

        if ($row === null || $row->status !== 'reconciled') {
            return;
        }

        // The first() above is a pre-read, not a lock (TOCTOU) — see the
        // linked architecture page for why the UPDATE re-asserts
        // status = 'reconciled' and the dispatch below is affected-count-gated.
        $affected = 0;

        $connection->transaction(function () use ($connection, $transactionId, $user, &$affected): void {
            $affected = $connection->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->where('status', 'reconciled')
                ->update([
                    'status' => 'cleared',
                    'updated_at' => $this->clock->now(),
                ]);
        });

        if ($affected === 0) {
            return;
        }

        $this->events->dispatch(new TransactionMutated(
            transactionId: $transactionId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['status' => 'cleared'],
        ));
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertOwnedAccount(User $user, int $accountId): void
    {
        $exists = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('id', $accountId)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException('Account not owned by the authenticated user.');
        }
    }
}
