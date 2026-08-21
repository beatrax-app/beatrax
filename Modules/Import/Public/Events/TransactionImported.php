<?php

declare(strict_types=1);

namespace Modules\Import\Public\Events;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;

// Once per row actually inserted, never for a duplicate or an enrichment
// update. Deliberately synchronous and in-transaction: a listener must see
// partner rows inserted earlier in the same outer transaction.
final readonly class TransactionImported
{
    public function __construct(
        public Transaction $transaction,
        public User $user,
    ) {}
}
