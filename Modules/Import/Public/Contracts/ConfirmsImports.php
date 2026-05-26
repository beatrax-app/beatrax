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
 *
 * The optional `$dispatchChain` flag controls whether the action
 * dispatches `ResolveChainLinksJob` (and the recurring-series detection
 * sweep) after its own transaction commits. Callers that wrap multiple
 * `ConfirmImport` calls inside their own outer transaction pass `false`
 * on every inner call and dispatch chain resolution ONCE themselves
 * after the outer transaction returns — that pattern is the load-bearing
 * fix for the race where the queue worker reads stale state because the
 * outer transaction has not yet committed when the inner dispatch fires.
 * Default `true` keeps the legacy single-run behaviour untouched.
 */
interface ConfirmsImports
{
    public function __invoke(int $importRunId, User $user, bool $dispatchChain = true): ImportConfirmResult;
}
