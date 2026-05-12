<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Import\Public\Dto\ImportConfirmResult;

/**
 * Confirms a previewed ImportRun: replays the cached canonical rows
 * through the Ledger's RecordsTransactions writer inside one DB transaction,
 * updates the ImportRun row with the result counts, and clears the preview
 * cache.
 */
interface ConfirmsImports
{
    public function __invoke(int $importRunId, User $user): ImportConfirmResult;
}
