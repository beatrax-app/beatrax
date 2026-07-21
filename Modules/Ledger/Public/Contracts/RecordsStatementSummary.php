<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\StatementSummaryData;

// Records statement-level metadata produced by an adapter for one
// import_run. CSV imports never reach this contract. Idempotent on
// (user_id, import_run_id) — calling it twice upserts the existing
// row, letting a re-preview refresh metadata without stale rows.
interface RecordsStatementSummary
{
    public function __invoke(User $user, StatementSummaryData $data): void;
}
