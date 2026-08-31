<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Public\Services\DeviceRegistryService;

// The two questions separating "this author is a stranger" from "this author
// is history". Neither admits a peer: admission stays the confirmed-only
// deviceKeys() map the verifier is constructed with, and these decide only
// whether an op ALREADY written can still be read back.
final class PriorAuthorship
{
    /** @var array<int, array<string, string>> */
    private array $retained = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DeviceRegistryService $registry,
    ) {}

    // Cached per user for the lifetime of one replay (a fresh instance is
    // built per OpLogReplayer), so a rebuild of a removed device's whole
    // history reads the registry once rather than once per op.
    public function retainedKeyFor(int $userId, string $deviceId): ?string
    {
        $this->retained[$userId] ??= $this->registry->retainedDeviceKeys($userId);

        return $this->retained[$userId][$deviceId] ?? null;
    }

    // Whether op_log_entries already holds this exact entry — same identity
    // AND signature. Only verified entries are persisted there, so a match is
    // proof this device accepted it once. Without it a rebuild deletes every
    // row a purged author created and then refuses to recreate them.
    public function alreadyAccepted(OpLogEntry $entry, int $userId): bool
    {
        if ($entry->signature === '') {
            return false;
        }

        return $this->db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('device_id', $entry->deviceId)
            ->where('table_name', $entry->table)
            ->where('pk', (string) $entry->pk)
            ->where('field', $entry->field)
            ->where('hlc_l', $entry->hlcL)
            ->where('hlc_c', $entry->hlcC)
            ->where('signature', $entry->signature)
            ->exists();
    }
}
