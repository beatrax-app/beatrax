<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Sync;

use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\CapturesImportForSync;
use Modules\Ledger\Models\ImportRun;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class NullImportSyncCapture implements CapturesImportForSync
{
    // The default when the Sync module is not loaded. An import on a device
    // that cannot sync is still a complete import; there is simply nowhere to
    // record it for a peer.
    public function capture(ImportRun $importRun, User $user): void {}
}
