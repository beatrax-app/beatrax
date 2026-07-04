<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Tax\Public\Events\TransactionUntagged;

/**
 * Removes the tax tag for a transaction.
 *
 * Fire-and-forget: silently no-ops when the tag does not exist or belongs
 * to a different user (0 rows deleted, no exception). This matches the
 * lifecycle-no-op convention (GoalWriter lifecycle methods).
 *
 * Leg-aware (Phase 13.1 D-06a): an optional trailing `$transactionSplitId`
 * scopes the delete to one leg's tag row so untagging one leg never deletes
 * the whole-transaction tag or a sibling leg's tag. Defaults to null
 * (whole-transaction untagging), matching every existing unsplit caller.
 *
 * Dispatches TransactionUntagged when a tag row was actually deleted, so
 * count caches (sidebar nav badge) can be invalidated (WR-06).
 *
 * Uses raw DatabaseManager only — no Eloquent statics.
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
            $this->events->dispatch(new TransactionUntagged(
                userId: $userId,
                transactionId: $transactionId,
            ));

            // Re-index so the note text is removed from search results.
            // Optional nullable injection — no-op when Search module is absent (RESEARCH A4).
            // Pass the authenticated actor (CR-02): the writer verifies ownership.
            $this->searchIndex?->upsertForTransaction($transactionId, $userId);
        }
    }
}
