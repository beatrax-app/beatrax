<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Ledger\Public\Support\StatementDifference;

// Both import screens ask this of the same row — the preview before the write
// and the results page after it — and a summary exists from the moment a file
// is previewed, so both can. One row per run: the table is unique on
// (user_id, import_run_id).
final readonly class StatementDifferenceForRun
{
    public function __construct(private DatabaseManager $db) {}

    // Never narrowed by StatementDenomination, unlike the reconcile prefill
    // beside it: the question here is whether the FILE added up, which it
    // answers in its own denomination whether or not that is the account's.
    public function for(int $importRunId, int $userId): ?StatementDifference
    {
        $row = $this->db->connection()->table('statement_summaries')
            ->where('user_id', $userId)
            ->where('import_run_id', $importRunId)
            ->first(['extras', 'closing_balance_currency']);

        return $row === null ? null : StatementDifference::readFrom(
            $row->extras ?? null,
            $row->closing_balance_currency ?? null,
        );
    }
}
