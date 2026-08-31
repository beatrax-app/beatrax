<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;

// Where a pre-sync capture got to, on disk, so the next pass continues instead
// of restarting. The op-log write and the cursor advance share one transaction,
// so the two can never disagree about what was captured.
/**
 * @link ../../../../.docs/features/sync/pre-sync-history-capture.md
 */
final readonly class BackfillProgress
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    // Starts a walk, or reopens one a completed run had closed: rows may have
    // appeared since, and row-wise idempotence makes a repeat cheap. A walk
    // still in flight keeps its cursor rather than restarting at the top.
    public function open(int $userId): void
    {
        if ($this->isOpen($userId)) {
            return;
        }

        $now = Instant::zulu($this->clock->now());

        $this->db->connection()->table('sync_backfill_state')->updateOrInsert(
            ['user_id' => $userId],
            [
                'cursor_table' => null,
                'cursor_pk' => null,
                'captured' => 0,
                'started_at' => $now,
                'completed_at' => null,
                'updated_at' => $now,
            ],
        );
    }

    // The one read the resume driver makes on every request it is allowed to
    // tick, so it is a covered lookup on a table holding one row per user.
    public function isOpen(int $userId): bool
    {
        return $this->db->connection()->table('sync_backfill_state')
            ->where('user_id', $userId)
            ->whereNull('completed_at')
            ->exists();
    }

    /**
     * @return array{table: string, pk: string}|null
     */
    public function cursor(int $userId): ?array
    {
        $row = $this->db->connection()->table('sync_backfill_state')
            ->where('user_id', $userId)
            ->whereNull('completed_at')
            ->first(['cursor_table', 'cursor_pk']);

        if ($row === null || ! is_string($row->cursor_table) || ! is_string($row->cursor_pk)) {
            return null;
        }

        return ['table' => $row->cursor_table, 'pk' => $row->cursor_pk];
    }

    public function advance(int $userId, string $table, int|string $pk, int $captured): void
    {
        $this->db->connection()->table('sync_backfill_state')
            ->where('user_id', $userId)
            ->increment('captured', $captured, [
                'cursor_table' => $table,
                'cursor_pk' => (string) $pk,
                'updated_at' => Instant::zulu($this->clock->now()),
            ]);
    }

    public function close(int $userId): void
    {
        $now = Instant::zulu($this->clock->now());

        $this->db->connection()->table('sync_backfill_state')
            ->where('user_id', $userId)
            ->update(['completed_at' => $now, 'updated_at' => $now]);
    }
}
