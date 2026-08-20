<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Ledger\Models\ImportRun;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
interface CapturesImportForSync
{
    // Records a confirmed import in the op log so the rows reach the user's
    // other devices. Called post-commit; implementations must not throw into
    // the import — a device that cannot capture has still imported.
    public function capture(ImportRun $importRun, User $user): void;
}
