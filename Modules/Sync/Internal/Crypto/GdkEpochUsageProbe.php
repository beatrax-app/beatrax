<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Illuminate\Database\DatabaseManager;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
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
}
