<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\OpLog\OpType;

// Whether an id a peer sent still names a row this device does not hold. A
// create that was refused leaves a hold under the pk it never reached, and
// that hold is the difference between an id the two devices agree on and one
// that merely happens to be in use here as well.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class UnplacedPeerCreates
{
    private const string TABLE = 'op_log_quarantine';

    public function __construct(
        private DatabaseManager $db,
        private PeerRowAliases $aliases,
    ) {}

    // An alias is the two devices having since agreed which row the id means,
    // so it answers ahead of the hold: a stale hold left beside one would
    // otherwise keep refusing children the translation can now place.
    public function refusedFor(string $table, string $deviceId, int|string $pk, int $userId): bool
    {
        if ($this->aliases->localFor($table, $deviceId, $pk, $userId) !== null) {
            return false;
        }

        return $this->db->connection()->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('table_name', $table)
            ->where('pk', (string) $pk)
            ->where('op_type', OpType::CreateRow->value)
            ->exists();
    }
}
