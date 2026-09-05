<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Sync\Public\Events\TransactionMutated;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus
 */
final readonly class TransactionStatusWriter
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    /**
     * @return int the number of rows actually transitioned to `reconciled`
     *             — 0 when nothing fell in the statement-date window, so
     *             callers can report the truthful outcome.
     */
    public function reconcileClearedUpTo(User $user, int $accountId, CarbonImmutable $statementDate): int
    {
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
                ->where('status', ClearedStatus::Cleared->value)
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
                ->where('status', ClearedStatus::Cleared->value)
                ->update([
                    'status' => ClearedStatus::Reconciled->value,
                    'updated_at' => $reconciledAt,
                ]);

            if ($affected === count($candidateIds)) {
                $transactionIds = $candidateIds;

                return;
            }

            $transactionIds = $connection->table('transactions')
                ->whereIn('id', $candidateIds)
                ->where('status', ClearedStatus::Reconciled->value)
                ->where('updated_at', $reconciledAt)
                ->pluck('id')
                ->map(static fn (mixed $id): int => self::toInt($id))
                ->all();
        });

        // Dispatched after commit — one per actually-transitioned row,
        // never a single synthetic bulk event.
        foreach ($transactionIds as $transactionId) {
            $this->announce($user, $transactionId, ClearedStatus::Reconciled);
        }

        return count($transactionIds);
    }

    // The escape hatch every locked-row refusal points the reader at. A foreign
    // or missing id, or one not currently reconciled, is a silent no-op.
    public function unreconcile(User $user, int $transactionId): void
    {
        if ($this->currentStatus($user, $transactionId) === ClearedStatus::Reconciled) {
            $this->write($user, $transactionId, ClearedStatus::Reconciled, ClearedStatus::Cleared);
        }
    }

    // The badge's toggle. Reconciled refuses here rather than falling through to
    // the un-reconcile edge the graph allows: leaving that state is a decision
    // the reader makes on the detail page, never a side effect of a tap.
    public function toggleCleared(User $user, int $transactionId): void
    {
        $current = $this->currentStatus($user, $transactionId);

        if ($current === null) {
            return;
        }

        $next = match ($current) {
            ClearedStatus::Cleared => ClearedStatus::Uncleared,
            ClearedStatus::Uncleared => ClearedStatus::Cleared,
            ClearedStatus::Reconciled => null,
        };

        if ($next !== null) {
            $this->write($user, $transactionId, $current, $next);
        }
    }

    // An importer adopting the flag its source carries for a row this device
    // already holds. A reconciled row refuses: the reader matched it against a
    // statement by hand, and a file cannot undo that silently. Un-reconciling
    // is how they take it back.
    /**
     * @return bool whether the column now says what the source said — true also
     *              when it already did, so a caller reports only real refusals
     */
    public function restateFromSource(User $user, int $transactionId, ClearedStatus $status): bool
    {
        $current = $this->currentStatus($user, $transactionId);

        if ($current === null || $current === ClearedStatus::Reconciled) {
            return false;
        }

        return $current === $status || $this->write($user, $transactionId, $current, $status);
    }

    private function currentStatus(User $user, int $transactionId): ?ClearedStatus
    {
        $raw = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->value('status');

        return is_string($raw) ? ClearedStatus::tryFrom($raw) : null;
    }

    // The one row-level write of the column. Callers above decide which value;
    // this decides whether the graph admits it, whether the row still holds the
    // value they read, and announces what actually landed. The pre-read is not
    // a lock, so the UPDATE re-asserts it and the dispatch is count-gated.
    private function write(User $user, int $transactionId, ClearedStatus $from, ClearedStatus $to): bool
    {
        if (! $from->canTransitionTo($to)) {
            return false;
        }

        $connection = $this->db->connection();
        $affected = 0;

        $connection->transaction(function () use ($connection, $transactionId, $user, $from, $to, &$affected): void {
            $affected = $connection->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->where('status', $from->value)
                ->update([
                    'status' => $to->value,
                    'updated_at' => $this->clock->now(),
                ]);
        });

        if ($affected === 0) {
            return false;
        }

        $this->announce($user, $transactionId, $to);

        return true;
    }

    private function announce(User $user, int $transactionId, ClearedStatus $status): void
    {
        $this->events->dispatch(new TransactionMutated(
            transactionId: $transactionId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['status' => $status->value],
        ));
    }
}
