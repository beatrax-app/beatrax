<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Contracts\AnchorsStartingBalanceFromStatements;

// The records a confirmed run is the only source of: the card statement its
// summary is promoted into, and the opening balance that anchors the account.
// Both rebuild from statement_summaries and both are safe to re-run, which is
// what lets uploading the file again recover one the reader deleted by hand.
/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#post-commit-dispatch-ordering
 */
final readonly class StatementDerivedRecords
{
    public function __construct(
        private UpsertsCardStatements $cardStatements,
        private AnchorsStartingBalanceFromStatements $startingBalances,
    ) {}

    public function promoteFor(int $importRunId, User $user): void
    {
        $this->cardStatements->upsertForImportRun($importRunId, $user);
        $this->startingBalances->anchorForUser($user);
    }
}
