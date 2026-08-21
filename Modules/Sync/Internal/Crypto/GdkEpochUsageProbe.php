<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Illuminate\Database\DatabaseManager;

final readonly class GdkEpochUsageProbe
{
    public function __construct(private DatabaseManager $db) {}

    // Whether anything in this device's durable op-log is encrypted under
    // $epochId. A key that has never been used is safe to discard; one that
    // has is the only way to read rows already written, so an epoch-id
    // collision must keep it and surface the conflict instead.
    public function hasLocalEntriesAt(int $userId, int $epochId): bool
    {
        return $this->db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('gdk_epoch', $epochId)
            ->exists();
    }

    // Whether this device's counterparty matching keys are already derived
    // under the blind-index key it holds. Adopting a peer's different key
    // after that point would leave every stored digest unmatchable by the
    // value a re-import computes, which is how a ledger doubles.
    /**
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
     */
    public function hasDerivedCounterpartyKeys(int $userId): bool
    {
        $row = $this->db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->first(['counterparty_key_backfilled_at']);

        return $row !== null && ($row->counterparty_key_backfilled_at ?? null) !== null;
    }
}
