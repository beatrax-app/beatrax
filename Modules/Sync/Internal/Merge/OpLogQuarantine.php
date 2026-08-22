<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\QuarantineReason;

final readonly class OpLogQuarantine
{
    public function __construct(private DatabaseManager $db) {}

    // A rejected or fail-closed entry is routed here, never to the
    // authoritative op_log_entries table. The write is best-effort: a
    // quarantine failure must never propagate, because replay has to
    // continue regardless of whether the audit row lands.
    public function record(OpLogEntry $entry, QuarantineReason $reason, string $now): void
    {
        try {
            $this->db->connection()->table('op_log_quarantine')->insert([
                'user_id' => $entry->userId,
                'table_name' => $entry->table,
                'pk' => (string) $entry->pk,
                'device_id' => $entry->deviceId,
                'reason' => $reason->value,
                // The epoch this entry needs, so a later pass can tell an
                // entry waiting for a key that is coming from one waiting for
                // a key this device has no way to obtain.
                'gdk_epoch' => $entry->gdkEpoch,
                'hlc_l' => $entry->hlcL,
                'hlc_c' => $entry->hlcC,
                'raw_value' => $entry->value,
                'created_at' => $now,
            ]);
        } catch (\Throwable) {
            // Never propagate a quarantine write failure — replay must
            // continue regardless of whether the audit row lands.
        }
    }
}
