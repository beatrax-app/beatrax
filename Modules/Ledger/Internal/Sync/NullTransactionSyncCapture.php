<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Sync;

use Modules\Core\Models\User;
use Modules\Ledger\Public\Contracts\CapturesTransactionsForSync;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class NullTransactionSyncCapture implements CapturesTransactionsForSync
{
    // The default when Sync is not loaded: a device that cannot sync has still
    // recorded the transactions, there is just no peer to tell.
    public function captureTransactions(array $transactionIds, User $user): void {}
}
