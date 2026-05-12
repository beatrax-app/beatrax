<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Dto\RecordResult;

/**
 * Public contract for the one path that writes rows to the `transactions`
 * table. Import (Plan 05+) injects this interface; the Ledger module's
 * `RecordTransactions` action is bound as the default implementation.
 */
interface RecordsTransactions
{
    /**
     * Persist a batch of canonical transactions inside a single DB transaction.
     * Duplicates (rows whose fingerprint already exists) are silently skipped
     * and counted in the returned result.
     *
     * @param  iterable<CanonicalTransaction>  $canonical
     */
    public function __invoke(iterable $canonical): RecordResult;
}
