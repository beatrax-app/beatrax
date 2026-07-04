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
     * @return int the number of rows actually transitioned to `reconciled`
     *             (WR-04) — 0 when nothing fell in the statement-date window,
     *             so callers can report the truthful outcome.
     *
     * @throws InvalidArgumentException when `$accountId` is not owned by `$user` (IDOR).
     */
    public function completeReconcile(User $user, int $accountId, CarbonImmutable $statementDate): int
    {
        $this->assertOwnedAccount($user, $accountId);

        $connection = $this->db->connection();
        $statementDateString = $statementDate->toDateString();
        $reconciledAt = $this->clock->now();

        // WR-02: capture the transitioned id set as an explicit SELECT taken
        // BEFORE the UPDATE, inside the same DB transaction — never re-select
        // by matching `updated_at = $reconciledAt` afterwards. Two
        // completeReconcile() calls landing in the same wall-clock second
        // (or sharing a frozen Clock) stamp the same `updated_at` value, so a
        // post-update re-select on that timestamp cannot distinguish rows
        // THIS call transitioned from rows a prior call already locked —
        // that produced an inflated "N rows locked" count (WR-04) and
        // duplicate `TransactionMutated('status' => 'reconciled')` dispatches
        // for already-reconciled rows. Capturing the id set up front and
        // driving both the UPDATE (via `whereIn`) and the dispatch loop from
        // that exact list makes the transitioned set unambiguous regardless
        // of timestamp collisions. The UPDATE re-asserts `status = 'cleared'`
        // because a deferred SQLite transaction takes its write lock at the
        // UPDATE, not the SELECT — a concurrent writer could flip a candidate
        // between the two. If that happens the affected count disagrees with
        // the candidate list, and the transitioned set is re-derived from the
        // candidates the UPDATE actually stamped (safe to match on
        // `updated_at = $reconciledAt` here: the whereIn confines it to rows
        // that were `cleared` at SELECT time, so no prior reconcile's rows
        // can be swept in).
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

        return count($transactionIds);
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

        // The `first()` above is a pre-read, not a lock — a row could flip
        // away from `reconciled` between that read and the UPDATE below
        // (TOCTOU). Re-asserting `status = 'reconciled'` on the UPDATE
        // itself (not just the pre-read) closes that window: if the row no
        // longer qualifies by the time the UPDATE runs, it matches zero
        // rows, and the event dispatch below is gated on that affected-row
        // count so a lost race never fires a spurious `cleared` event.
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
