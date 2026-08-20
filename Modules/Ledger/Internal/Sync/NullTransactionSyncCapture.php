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
    // The default when the Sync module is not loaded. A device that cannot
    // sync has still recorded the transactions; there is simply nowhere to
    // record them for a peer.
    public function captureTransactions(array $transactionIds, User $user): void {}
}
