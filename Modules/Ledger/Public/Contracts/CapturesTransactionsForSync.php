<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
interface CapturesTransactionsForSync
{
    // Records newly written transactions in the op log so they reach the
    // user's other devices, together with the import run and account each one
    // names. Called post-commit; implementations must not throw into the
    // write — a device that cannot capture has still recorded the money.
    /**
     * @param  list<int>  $transactionIds
     */
    public function captureTransactions(array $transactionIds, User $user): void;
}
