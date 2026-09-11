<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Psr\Log\LoggerInterface;

final readonly class OpLogQuarantine
{
    public function __construct(
        private DatabaseManager $db,
        private LoggerInterface $log,
    ) {}

    // The highest hold id standing before a pass runs, so the pass can tell a
    // refusal it just recorded from one that was already here.
    public function latestHoldId(int $userId): int
    {
        /** @var mixed $id */
        $id = $this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->max('id');

        return is_numeric($id) ? (int) $id : 0;
    }

    // Whether the pass just turned this row away for something that is not a
    // verdict on its collision — a column it could not seal, a parent that is
    // not here. The create never reached the id, so the collision hold it was
    // retried from has had no answer and has to stand.
    public function refusedPastTheCollision(int $userId, string $deviceId, string $table, string $pk, int $sinceId): bool
    {
        return $this->db->connection()->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('table_name', $table)
            ->where('pk', $pk)
            ->where('id', '>', $sinceId)
            ->whereNotIn('reason', QuarantineReason::collisionVerdicts())
            ->exists();
    }

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
                // Which op was refused, not just why. A hold on a create says
                // the pk it names reached no row here, and no reason can say
                // that on its own -- a key that would not open is recorded
                // the same whether it held a create or an edit.
                'op_type' => $entry->opType->value,
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
        } catch (\Throwable $e) {
            // Never propagated: replay must continue whether or not the audit
            // row lands. Never silent either — this row IS the record that the
            // entry existed, so losing it loses the entry with nothing left
            // anywhere to say an entry was ever dropped.
            try {
                $this->log->error('OpLogQuarantine: the quarantine row could not be written, so this entry was dropped with no record of it.', [
                    'table' => $entry->table,
                    'pk' => (string) $entry->pk,
                    'device_id' => $entry->deviceId,
                    'reason' => $reason->value,
                    'exception' => $e::class,
                ]);
            } catch (\Throwable) {
                // The second channel, and a full disk is where it fails too.
                // Taking replay down here would turn one lost audit row into a
                // merge that stopped.
            }
        }
    }
}
