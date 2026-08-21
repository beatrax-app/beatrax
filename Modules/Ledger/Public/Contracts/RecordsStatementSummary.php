<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\StatementSummaryData;

// CSV imports never reach this contract. Idempotent on (user_id,
// import_run_id): a second call upserts, so a re-preview refreshes the
// metadata instead of leaving a stale row behind.
interface RecordsStatementSummary
{
    public function __invoke(User $user, StatementSummaryData $data): void;
}
