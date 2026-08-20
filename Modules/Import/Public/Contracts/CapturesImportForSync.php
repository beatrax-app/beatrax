<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Ledger\Models\ImportRun;

interface CapturesImportForSync
{
    // Called post-commit; an implementation must not throw into the import,
    // because a device that cannot capture has still imported.
    public function capture(ImportRun $importRun, User $user): void;
}
