<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Dto\RecordResult;

/**
 * Public contract for the one path that writes rows to the `transactions`
 * table. Consumer modules inject this interface; the Ledger module's
 * `RecordTransactions` action is bound as the default implementation.
 */
interface RecordsTransactions
{
    /**
     * Persist a batch of canonical transactions inside a single DB transaction.
     * Duplicates (rows whose fingerprint already exists) are silently skipped
     * and counted in the returned result.
     *
     * Every row must carry a non-null `userId`. Implementations MUST reject
     * the batch (without persisting any row) when a row's `userId` is null,
     * because SQLite treats NULL as distinct in the UNIQUE indexes that guard
     * idempotency and a null-user row could not be deduplicated on re-import.
     *
     * The `$user` parameter is forwarded into the `TransactionImported`
     * event payload that fires per persisted row, so cross-module
     * listeners (e.g., transfer-pair detection) receive the importing
     * User model without an extra DB lookup per row.
     *
     * @param  iterable<CanonicalTransaction>  $canonical
     */
    public function __invoke(iterable $canonical, User $user): RecordResult;
}
