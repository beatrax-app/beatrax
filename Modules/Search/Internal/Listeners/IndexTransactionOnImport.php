<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Listeners;

use Modules\Import\Public\Events\TransactionImported;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;

// Synchronous index listener — the FTS5 index updates in the same DB
// write as the transaction insert. Exceptions are NOT caught here; a
// failed upsert must bubble so the outer import-chunk transaction
// rolls back cleanly rather than leaving the index desynced.
final readonly class IndexTransactionOnImport
{
    public function __construct(
        private SearchIndexWriterContract $writer,
    ) {}

    public function handle(TransactionImported $event): void
    {
        $this->writer->upsertForTransaction($event->transaction->id, $event->user->id);
    }
}
