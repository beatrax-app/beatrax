<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\StatementSummaryData;

/**
 * Records statement-level metadata produced by an adapter (CAMT.053 or
 * MT940) for one import_run. CSV imports never reach this contract.
 *
 * The writer is idempotent on `(user_id, import_run_id)` — calling it twice
 * for the same import run upserts the existing row rather than throwing.
 * The upsert semantics let a re-preview refresh the metadata without
 * leaving stale rows behind.
 */
interface RecordsStatementSummary
{
    public function __invoke(User $user, StatementSummaryData $data): void;
}
