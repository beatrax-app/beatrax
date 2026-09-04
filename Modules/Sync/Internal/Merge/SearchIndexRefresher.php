<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\OpLog\QuarantineReason;

// Brings the full-text index back in line with the rows a replay changed —
// which rows those are, and which document each belongs to, is SearchDocumentRows'
// answer. Nothing here can fail the replay: a stale index recovers on the next
// write, a half-applied replay does not.
final readonly class SearchIndexRefresher
{
    private const string SYSTEM_FTS_DEVICE_ID = 'system-fts';

    public function __construct(
        private DatabaseManager $db,
        private ?SearchIndexWriterContract $searchWriter = null,
    ) {}

    public function refresh(SearchDocumentRows $documents, int $userId, string $now): void
    {
        if ($this->searchWriter === null) {
            return;
        }

        foreach ($documents->touched() as $txId) {
            try {
                $this->searchWriter->upsertForTransaction($txId, $userId);
            } catch (\Throwable) {
                $this->quarantineSearchError($txId, 'upsert', $userId, $now);
            }
        }

        // Rebuilds first, drops second: a transaction both rebuilt and deleted
        // in one replay has to end up gone, not re-indexed.
        foreach ($documents->tombstoned() as $txId) {
            try {
                $this->searchWriter->deleteForTransaction($txId, $userId);
            } catch (\Throwable) {
                $this->quarantineSearchError($txId, 'delete', $userId, $now);
            }
        }
    }

    /**
     * @param  string  $operation  'upsert'|'delete'
     */
    private function quarantineSearchError(int $transactionId, string $operation, int $userId, string $now): void
    {
        try {
            $this->db->connection()->table('op_log_quarantine')->insert([
                'user_id' => $userId,
                'table_name' => 'transactions',
                'pk' => (string) $transactionId,
                'device_id' => self::SYSTEM_FTS_DEVICE_ID,
                'reason' => QuarantineReason::StrategyError->value,
                'hlc_l' => 0,
                'hlc_c' => 0,
                'raw_value' => json_encode(['fts_operation' => $operation], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        } catch (\Throwable) {
            // Never propagate — an FTS-freshness quarantine failure must
            // not break replay.
        }
    }
}
