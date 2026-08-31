<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\PersistedOpLogEntries;

// Widens a frame-sized batch back out to every op the durable log holds for the
// rows that batch names. The verifier persists before the merge runs, so by the
// time this reads, op_log_entries already holds both the arriving ops and every
// op this device accepted for the same rows before them.
/**
 * @link ../../../../.docs/features/sync/op-log-merge-rules.md#a-batch-is-not-the-set-a-strategy-resolves-over
 */
final readonly class RowHistoryRehydration
{
    public function __construct(
        private PersistedOpLogEntries $persisted,
        private OpLogEntryVerifier $verifier,
    ) {}

    /**
     * @param  list<OpLogEntry>  $batch  Verified and already persisted.
     * @return list<OpLogEntry>
     */
    public function augment(array $batch, int $userId): array
    {
        if ($batch === []) {
            return [];
        }

        $seen = [];

        foreach ($batch as $entry) {
            $seen[self::identity($entry)] = true;
        }

        $stored = $this->persisted->forRows($userId, $this->rowKeys($batch));
        $merged = $batch;

        // The batch copy wins any collision: it is the one the verifier has
        // already decrypted and re-scoped, and the stored row is the same op.
        foreach ($this->verifier->prepareStored($stored, $userId) as $entry) {
            $identity = self::identity($entry);

            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $merged[] = $entry;
        }

        return $merged;
    }

    /**
     * @param  list<OpLogEntry>  $batch
     * @return list<array{table: string, pk: string}>
     */
    private function rowKeys(array $batch): array
    {
        $rows = [];
        $seen = [];

        foreach ($batch as $entry) {
            $key = $entry->table."\0".$entry->pk;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = ['table' => $entry->table, 'pk' => (string) $entry->pk];
        }

        return $rows;
    }

    // The unique key op_log_entries is upserted on, so the same op read back
    // from disk collides with the copy that has just been written.
    private static function identity(OpLogEntry $entry): string
    {
        return implode("\0", [
            $entry->deviceId,
            $entry->table,
            (string) $entry->pk,
            $entry->field,
            (string) $entry->hlcL,
            (string) $entry->hlcC,
        ]);
    }
}
