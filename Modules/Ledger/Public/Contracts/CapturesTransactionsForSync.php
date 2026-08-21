<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
interface CapturesTransactionsForSync
{
    // Called post-commit, and an implementation must not throw back into the
    // write: a device that cannot capture has still recorded the money.
    /**
     * @param  list<int>  $transactionIds
     */
    public function captureTransactions(array $transactionIds, User $user): void;
}
