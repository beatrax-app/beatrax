<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;

/**
 * Public contract for the one path that mutates `transactions.note` on
 * behalf of a user (manual note editing today; the Plan 05 rule engine
 * tomorrow). Ledger's `SetTransactionNote` action is bound as the default
 * implementation.
 */
interface SetsTransactionNote
{
    /**
     * Set (`mode = 'set'`) or append onto (`mode = 'append'`) the
     * transaction's note. Returns the number of rows affected — 0 when
     * the transaction does not belong to the user, the row is
     * `reconciled` (D-08 lock), or the resolved value is unchanged
     * (write-only-on-change); 1 on success.
     */
    public function __invoke(int $transactionId, ?string $text, string $mode, User $user): int;
}
