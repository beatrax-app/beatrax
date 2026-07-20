<?php

declare(strict_types=1);

namespace Modules\Import\Public\Events;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;

// Dispatched once per Transaction row INSERTED (never for duplicates
// or ApplyEnrichments updates). Synchronous, in-transaction — no
// ShouldHandleEventsAfterCommit/ShouldQueue, since listeners must see
// partner rows inserted earlier in the same outer DB transaction.
final readonly class TransactionImported
{
    public function __construct(
        public Transaction $transaction,
        public User $user,
    ) {}
}
