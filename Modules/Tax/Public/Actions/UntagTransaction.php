<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Tax\Public\Events\TransactionUntagged;

/**
 * Removes the tax tag for a transaction.
 *
 * Fire-and-forget: silently no-ops when the tag does not exist or belongs
 * to a different user (0 rows deleted, no exception). This matches the
 * lifecycle-no-op convention (GoalWriter lifecycle methods).
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

    public function execute(int $userId, int $transactionId): void
    {
        $deleted = $this->db->connection()
            ->table('tax_transaction_tags')
            ->where('user_id', $userId)
            ->where('transaction_id', $transactionId)
            ->delete();

        if ($deleted > 0) {
            $this->events->dispatch(new TransactionUntagged(
                userId: $userId,
                transactionId: $transactionId,
            ));

            // Re-index so the note text is removed from search results.
            // Optional nullable injection — no-op when Search module is absent (RESEARCH A4).
            $this->searchIndex?->upsertForTransaction($transactionId);
        }
    }
}
