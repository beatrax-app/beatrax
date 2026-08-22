<?php

declare(strict_types=1);

namespace Modules\Import\Public\Events;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;

// Once per row actually inserted, never for a duplicate or an enrichment
// update. Dispatched by RecordTransactions AFTER each chunk commits, never
// inside it: the listeners are synchronous, and a rollback had left the search
// index and the transfer pairing acting on rows that had vanished.
final readonly class TransactionImported
{
    public function __construct(
        public Transaction $transaction,
        public User $user,
    ) {}
}
