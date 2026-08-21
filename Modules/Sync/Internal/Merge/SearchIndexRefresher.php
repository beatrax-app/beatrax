<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\OpLog\QuarantineReason;

// Brings the full-text index back in line with the rows a replay changed. A
// different subsystem from the applier: that one merges op-log entries into
// their tables, this re-derives an index from the result. Nothing here can
// fail the replay — a stale index recovers, a half-applied replay does not.
final readonly class SearchIndexRefresher
{
    private const string SYSTEM_FTS_DEVICE_ID = 'system-fts';

    public function __construct(
        private DatabaseManager $db,
        private ?SearchIndexWriterContract $searchWriter = null,
    ) {}

    /**
     * @param  list<int>  $touchedTransactionIds
     * @param  list<int>  $tombstonedTransactionIds
     */
    public function refresh(
        array $touchedTransactionIds,
        array $tombstonedTransactionIds,
        int $userId,
        string $now,
    ): void {
        if ($this->searchWriter === null) {
            return;
        }

        foreach ($touchedTransactionIds as $txId) {
            try {
                $this->searchWriter->upsertForTransaction($txId, $userId);
            } catch (\Throwable) {
                $this->quarantineSearchError($txId, 'upsert', $userId, $now);
            }
        }

        foreach ($tombstonedTransactionIds as $txId) {
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
