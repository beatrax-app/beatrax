<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Search\Public\Contracts\SearchIndexRepairContract;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Psr\Log\LoggerInterface;

// Brings the full-text index back in line with the rows a replay changed —
// which rows those are, and which document each belongs to, is SearchDocumentRows'
// answer. Nothing here can fail the replay: a stale index recovers on the next
// write, a half-applied replay does not.
final readonly class SearchIndexRefresher
{
    // The op was applied and the row is here; only the derived index missed it.
    // This used to be filed as a quarantined operation under a device id no
    // registry holds, which named a refusal that never happened.
    /**
     * @link ../../../../.docs/features/sync/an-index-that-missed-a-row-refused-nothing.md
     */
    // Public because the rebuild reaches the same failure by another route,
    // and two spellings of one sentence are two recoveries a reader has to
    // recognise as the same one.
    public const string STALE = 'SearchIndexRefresher: the search index could not be brought in line with a row the replay applied; the row is stored, and its index doc is owed to the repair queue that drains on the next unlocked request.';

    public function __construct(
        private ?SearchIndexWriterContract $searchWriter = null,
        private ?LoggerInterface $log = null,
        private ?SearchIndexRepairContract $repairs = null,
    ) {}

    public function refresh(SearchDocumentRows $documents, int $userId): void
    {
        if ($this->searchWriter === null) {
            return;
        }

        foreach ($documents->touched() as $txId) {
            try {
                $this->searchWriter->upsertForTransaction($txId, $userId);
            } catch (\Throwable $e) {
                $this->reportStaleIndex($txId, 'upsert', $userId, $e);
            }
        }

        // Rebuilds first, drops second: a transaction both rebuilt and deleted
        // in one replay has to end up gone, not re-indexed.
        foreach ($documents->tombstoned() as $txId) {
            try {
                $this->searchWriter->deleteForTransaction($txId, $userId);
            } catch (\Throwable $e) {
                $this->reportStaleIndex($txId, 'delete', $userId, $e);
            }
        }
    }

    /**
     * @param  string  $operation  'upsert'|'delete'
     */
    private function reportStaleIndex(int $transactionId, string $operation, int $userId, \Throwable $e): void
    {
        // Recorded before it is reported, because the record is the recovery
        // and the line is only the account of it. "A stale index recovers on
        // the next write" holds for a row someone edits again; a row that
        // arrived by sync may never be written here again at all.
        try {
            $this->repairs?->owe($userId, $transactionId);
        } catch (\Throwable) {
            // The queue is on the same disk as the index that just failed, so
            // it can fail for the same reason. The warning below is then the
            // only account there is, which is the state this replaces.
        }

        try {
            $this->log?->warning(self::STALE, [
                'table' => 'transactions',
                'pk' => (string) $transactionId,
                'ftsOperation' => $operation,
                'userId' => $userId,
                'recoverWith' => $this->repairs === null ? 'search:reindex' : 'the repair queue, drained on the next unlocked request',
                ...SafeExceptionContext::describe($e),
            ]);
        } catch (\Throwable) {
            // The report is the second channel and a full disk is where it
            // fails; taking replay down with it would turn a row missing from
            // search into a merge that stopped.
        }
    }
}
