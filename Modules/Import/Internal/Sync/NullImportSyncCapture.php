<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Sync;

use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\CapturesImportForSync;
use Modules\Ledger\Models\ImportRun;

final class NullImportSyncCapture implements CapturesImportForSync
{
    // The default when Sync is not loaded: an import on a device that cannot
    // sync is still a complete import.
    public function capture(ImportRun $importRun, User $user): void {}
}
