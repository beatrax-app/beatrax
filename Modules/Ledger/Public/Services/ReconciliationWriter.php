<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Events\TransactionMutated;

/**
 * The terminal, history-protecting write path for D-08 account reconciliation
 * (Req SC-2). Mirrors `EnvelopeWriter`'s shape: one DB transaction per
 * operation, events dispatched only AFTER commit (WR-06), every
 * client-supplied id re-validated as user-owned before any write (IDOR).
 *
 * `completeReconcile()` bulk-transitions an account's `cleared` transactions
 * up to (and including) the statement date to `reconciled` — the confirming
 * action of the reconcile flow (13.3-06 is a thin caller). `unreconcile()` is
 * the escape hatch that reverts a single row back to `cleared`.
 *
 * CRDT-correctness (SC-3): a bulk status transition is NEVER represented as a
 * single synthetic sync event. Every transitioned row gets its own
 * `TransactionMutated('edit', ['status' => 'reconciled'])`, dispatched in a
 * loop after the transaction commits (RESEARCH.md Anti-Patterns).
 */
final class ReconciliationWriter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Transition every `cleared` transaction for `$accountId` posted on or
     * before `$statementDate` to `reconciled`, scoped to `$user`. `uncleared`
     * rows and rows posted after the statement date are left untouched.
     *
     * @throws InvalidArgumentException when `$accountId` is not owned by `$user` (IDOR).
     */
    public function completeReconcile(User $user, int $accountId, CarbonImmutable $statementDate): void
    {
        $this->assertOwnedAccount($user, $accountId);

        $connection = $this->db->connection();
        $statementDateString = $statementDate->toDateString();
        $reconciledAt = $this->clock->now();

        // WR-02: capture the transitioned id set from POST-UPDATE membership,
        // not a pre-transaction snapshot. The UPDATE is guarded by
        // `status = 'cleared'`, so a row a concurrent toggle flipped to
        // `uncleared` between read and write is (correctly) skipped; re-selecting
        // the rows this write actually stamped (`status = reconciled` AND
        // `updated_at = $reconciledAt`) guarantees the dispatch loop emits an
        // event ONLY for rows that genuinely transitioned — never a spurious
        // `status => reconciled` op for a row the DB left `uncleared`.
        $transactionIds = [];

        $connection->transaction(function () use ($connection, $accountId, $user, $statementDateString, $reconciledAt, &$transactionIds): void {
            $connection->table('transactions')
                ->where('account_id', $accountId)
                ->where('user_id', $user->id)
                ->where('status', 'cleared')
                ->where('posted_at', '<=', $statementDateString)
                ->update([
                    'status' => 'reconciled',
                    'updated_at' => $reconciledAt,
                ]);

            $transactionIds = $connection->table('transactions')
                ->where('account_id', $accountId)
                ->where('user_id', $user->id)
                ->where('status', 'reconciled')
                ->where('updated_at', $reconciledAt)
                ->where('posted_at', '<=', $statementDateString)
                ->pluck('id')
                ->map(static fn (mixed $id): int => self::toInt($id))
                ->all();
        });

        // Events dispatched AFTER commit (D-08) — one per actually-transitioned
        // row (SC-3: never a single synthetic bulk event).
        foreach ($transactionIds as $transactionId) {
            $this->events->dispatch(new TransactionMutated(
                transactionId: $transactionId,
                userId: $user->id,
                mutationType: 'edit',
                dirtyFields: ['status' => 'reconciled'],
            ));
        }
    }

    /**
     * Revert a single `reconciled` transaction back to `cleared`, scoped by
     * `user_id`. A foreign or missing transaction id, or one that is not
     * currently `reconciled`, is a silent no-op (mirrors `PotWriter::archive`'s
     * cross-user handling).
     */
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

        $connection->transaction(function () use ($connection, $transactionId, $user): void {
            $connection->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->update([
                    'status' => 'cleared',
                    'updated_at' => $this->clock->now(),
                ]);
        });

        $this->events->dispatch(new TransactionMutated(
            transactionId: $transactionId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['status' => 'cleared'],
        ));
    }

    /**
     * Re-validate that `$accountId` belongs to `$user` (T-13.3-10 IDOR guard)
     * — never trust a caller-supplied accountId without re-checking ownership.
     *
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

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
