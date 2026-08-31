<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Contracts\DeletesTransaction;
use Modules\Ledger\Public\Contracts\UnpairsTransferLegs;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;
use stdClass;

final readonly class DeleteTransaction implements DeletesTransaction
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
        private SearchIndexWriterContract $searchIndex,
        private UnpairsTransferLegs $unpairer,
    ) {}

    public function delete(User $user, int $transactionId): bool
    {
        /** @var list<object> $dispatchAfterCommit */
        $dispatchAfterCommit = [];

        $deleted = $this->db->connection()->transaction(
            function () use ($user, $transactionId, &$dispatchAfterCommit): bool {
                // Re-read inside the transaction — TOCTOU-safe, mirrors
                // SaveTransactionSplit::save().
                $row = $this->db->connection()
                    ->table('transactions')
                    ->where('id', $transactionId)
                    ->where('user_id', $user->id)
                    ->first(['status', 'type', 'pair_transaction_id']);

                if ($row === null || TransactionStatusQuery::locksEdits($row->status)) {
                    return false;
                }

                $dispatchAfterCommit = $this->applyDelete($user, $transactionId, $row);

                return true;
            },
        );

        if ($deleted !== true) {
            return false;
        }

        // Dispatch after the transaction commits — never from inside an open
        // DB::transaction() closure.
        foreach ($dispatchAfterCommit as $event) {
            $this->events->dispatch($event);
        }

        return true;
    }

    /**
     * @return list<object>
     */
    private function applyDelete(User $user, int $transactionId, stdClass $row): array
    {
        // Read the leg ids before the parent delete cascades them away:
        // convergence cannot assume the peer's replay connection has FK cascade
        // on, so each leg needs its own tombstone.
        $legIds = $this->db->connection()
            ->table('transaction_splits')
            ->where('transaction_id', $transactionId)
            ->where('user_id', $user->id)
            ->pluck('id');

        $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->delete();

        // search_body is the deliberate plaintext shadow of the encrypted name
        // and description, with no FK, no cascade and no trigger. Only a PEER's
        // delete was reaped, so a row the reader deleted themselves left its
        // decrypted text on disk and red-flagged the FTS health check.
        $this->searchIndex->deleteForTransaction($transactionId, $user->id);

        $events = [new TransactionMutated(
            transactionId: $transactionId,
            userId: $user->id,
            mutationType: 'delete',
            dirtyFields: [],
        )];

        foreach ($legIds as $legId) {
            $events[] = new TransactionSplitMutated(
                splitId: self::toInt($legId),
                transactionId: $transactionId,
                userId: $user->id,
                mutationType: 'delete',
            );
        }

        $survivorEdit = $this->retypeSurvivor($user, $row->pair_transaction_id, $row->type);
        if ($survivorEdit !== null) {
            $events[] = $survivorEdit;
        }

        return $events;
    }

    // The survivor's own pair_transaction_id NULL-ing is deliberately absent
    // from dirtyFields: the FK sets it, and the merge engine cascades it.
    private function retypeSurvivor(User $user, mixed $pairId, mixed $deletedType): ?TransactionMutated
    {
        $type = TransactionType::tryFrom(self::toString($deletedType));
        if (! is_numeric($pairId) || $type === null) {
            return null;
        }

        $survivorId = (int) $pairId;
        $newType = $this->unpairer->unpair($user->id, $survivorId, $type);

        if ($newType === null) {
            return null;
        }

        return new TransactionMutated(
            transactionId: $survivorId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['type' => $newType->value],
        );
    }
}
