<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

/**
 * Dispatched for every user-driven mutation to a transaction_splits row.
 *
 * Mirrors TransactionMutated's contract but targets the leg table. The two
 * events are intentionally NOT unified: SyncCaptureListener's handlers
 * hard-code the target table name per event type
 * (`table: 'transactions'` for TransactionMutated), so a transaction_splits
 * mutation dispatched as TransactionMutated would silently corrupt replay by
 * writing an op-log row claiming `table_name = 'transactions'` for a
 * transaction_splits primary key.
 *
 * `splitId` is the leg's STABLE `transaction_splits.id` primary key — it is
 * the SAME id across an edit (SaveTransactionSplit's PK-preserving
 * UPDATE/DELETE/INSERT diff never regenerates a surviving leg's id). This is
 * what lets per-(table, pk, field) LWW merge concurrent edits of the same
 * split converge correctly (D-09, D-12, Req 10).
 *
 * Synchronous dispatch — does NOT implement ShouldQueue.
 */
final readonly class TransactionSplitMutated
{
    /**
     * @param  string  $mutationType  'create' | 'edit' | 'delete'
     * @param  array<string, mixed>  $dirtyFields  Changed field → new-value map.
     *                                             Empty for delete ops.
     */
    public function __construct(
        public int $splitId,
        public int $transactionId,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
