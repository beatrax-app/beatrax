<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Tax\Public\Events\TransactionUntagged;

/**
 * @link ../../../../.docs/features/tax/architecture.md
 */
final class UntagTransaction
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
        private readonly ?SearchIndexWriterContract $searchIndex = null,
    ) {}

    public function execute(int $userId, int $transactionId, ?int $transactionSplitId = null): void
    {
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

            // Re-index so the note text is removed from search results; a
            // no-op when the Search module is absent. The writer
            // re-verifies ownership from the passed actor id.
            $this->searchIndex?->upsertForTransaction($transactionId, $userId);
        }
    }
}
