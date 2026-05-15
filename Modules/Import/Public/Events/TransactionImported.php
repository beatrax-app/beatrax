<?php

declare(strict_types=1);

namespace Modules\Import\Public\Events;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;

/**
 * Dispatched once per Transaction row INSERTED by the import pipeline.
 *
 * NOT fired for duplicates that `insertOrIgnore` silently dropped, NOT
 * fired for enriched rows updated by `ApplyEnrichments`.
 *
 * Synchronous, in-transaction dispatch — the class deliberately does
 * NOT implement `ShouldHandleEventsAfterCommit` or `ShouldQueue`.
 * Listeners (e.g., cross-row transfer-pair detection) need to see
 * partner rows inserted earlier in the same outer DB transaction.
 */
final readonly class TransactionImported
{
    public function __construct(
        public Transaction $transaction,
        public User $user,
    ) {}
}
