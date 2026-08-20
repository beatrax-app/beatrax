<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Dto\RecordResult;

/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
interface RecordsTransactions
{
    // Duplicates (fingerprint already exists) are silently skipped and
    // counted in the result. Implementations MUST reject the batch
    // when a row's userId is null — SQLite treats NULL as distinct in
    // the UNIQUE indexes that guard idempotency.
    /**
     * @param  iterable<CanonicalTransaction>  $canonical
     */
    public function __invoke(iterable $canonical, User $user, bool $captureForSync = true): RecordResult;
}
