<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Tax\Public\Events\TransactionUntagged;

final readonly class UntagTransaction
{
    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
        private ?SearchIndexWriterContract $searchIndex = null,
    ) {}

    public function execute(int $userId, int $transactionId, ?int $transactionSplitId = null): void
    {
        // The rule engine, a bulk untag and a replay all reach this action
        // without passing the page's own lock, and a tag is exactly the
        // classification a reconcile froze.
        $status = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->value('status');

        if (TransactionStatusQuery::locksEdits($status)) {
            return;
        }

        // Read the id before the delete: a tombstone needs the pk, and after
        // the row is gone there is nothing left to name it by.
        $tagId = $this->db->connection()
            ->table('tax_transaction_tags')
            ->where('user_id', $userId)
            ->where('transaction_id', $transactionId)
            ->when(
                $transactionSplitId === null,
                static fn (QueryBuilder $q) => $q->whereNull('transaction_split_id'),
                static fn (QueryBuilder $q) => $q->where('transaction_split_id', $transactionSplitId),
            )
            ->value('id');

        $deleted = $this->db->connection()
            ->table('tax_transaction_tags')
            ->where('user_id', $userId)
            ->where('transaction_id', $transactionId)
            ->when(
                $transactionSplitId === null,
                static fn (QueryBuilder $q) => $q->whereNull('transaction_split_id'),
                static fn (QueryBuilder $q) => $q->where('transaction_split_id', $transactionSplitId),
            )
            ->delete();

        if ($deleted > 0) {
            if (is_numeric($tagId)) {
                $this->events->dispatch(new EntityMutated(
                    table: 'tax_transaction_tags',
                    pk: (int) $tagId,
                    userId: $userId,
                    mutationType: 'delete',
                ));
            }

            $this->events->dispatch(new TransactionUntagged(
                userId: $userId,
                transactionId: $transactionId,
            ));

            // Re-index so the note text leaves the search results; a no-op when
            // the Search module is absent, and the writer re-checks ownership.
            $this->searchIndex?->upsertForTransaction($transactionId, $userId);
        }
    }
}
