<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Listeners;

use Modules\Import\Public\Events\TransactionImported;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;

/**
 * Synchronous index listener for the TransactionImported event.
 *
 * Registered by the Plan 01 class_exists()-guarded block in
 * SearchServiceProvider::boot(). Calls SearchIndexWriterContract so the
 * FTS5 index is always updated in the same DB write as the transaction
 * insert (D-23 — searchable immediately, same write, no queue).
 *
 * Pitfall-2 safety: exceptions are NOT caught here. If the FTS upsert
 * fails, the failure bubbles up so the outer import chunk DB transaction
 * rolls back cleanly, preventing a desync between the transactions table
 * and the FTS index.
 *
 * INTERFACE NOTE: TransactionImported carries a Transaction model + User.
 * The listener reads $event->transaction->id (NOT $event->transactionId —
 * that property does not exist on the event).
 */
final class IndexTransactionOnImport
{
    public function __construct(
        private readonly SearchIndexWriterContract $writer,
    ) {}

    public function handle(TransactionImported $event): void
    {
        // Transaction::$id is typed int in the model @property — no cast needed.
        $this->writer->upsertForTransaction($event->transaction->id);
    }
}
