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
    // Past this many consecutive fruitless slices the walk stalls: still owed,
    // never finished, and no longer retried on every request tail. Only a user
    // action reopens it, because a condition this many slices apart cannot be
    // the transient lock the first retry is for.
    public const int MAX_FAILED_SLICES = 8;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    // Reopens a completed walk, because rows may have appeared since. A walk
    // nobody has finished keeps what it has -- its cursor, and the fruitless
    // slices that stalled it. Reading isOpen() here instead made the stall
    // self-clearing, since the request tail reopens on every tick it is allowed.
    public function open(int $userId): void
    {
        if ($this->isUnfinished($userId)) {
            return;
        }

        $now = Instant::zulu($this->clock->now());

        $this->db->connection()->table('sync_backfill_state')->updateOrInsert(
            ['user_id' => $userId],
            [
                'cursor_table' => null,
                'cursor_pk' => null,
                'captured' => 0,
                'failed_slices' => 0,
                'started_at' => $now,
                'completed_at' => null,
                'updated_at' => $now,
            ],
        );
    }

    // Open or stalled: two states of one walk nobody has finished, and neither
    // wants its cursor or its stall count replaced under it.
    private function isUnfinished(int $userId): bool
    {
        return $this->db->connection()->table('sync_backfill_state')
            ->where('user_id', $userId)
            ->whereNull('completed_at')
            ->exists();
    }

    // The reader asked for this walk, so the stall goes with the asking. The
    // count infers a permanent condition from repetition alone, and pairing a
    // device that carries the epoch the walk could not read is exactly the new
    // information repetition cannot hold.
    public function clearStall(int $userId): void
    {
        $this->db->connection()->table('sync_backfill_state')
            ->where('user_id', $userId)
            ->whereNull('completed_at')
            ->update(['failed_slices' => 0, 'updated_at' => Instant::zulu($this->clock->now())]);
    }

    // The one read the resume driver makes on every request it is allowed to
    // tick, so it is a covered lookup on a table holding one row per user.
    public function isOpen(int $userId): bool
    {
        return $this->db->connection()->table('sync_backfill_state')
            ->where('user_id', $userId)
            ->whereNull('completed_at')
            ->where('failed_slices', '<', self::MAX_FAILED_SLICES)
            ->exists();
    }

    // Finished, as against merely not being worked on: a walk that stalled is
    // neither. The two used to be one answer, and a stalled walk reported
    // itself as a capture that had covered everything.
    public function isComplete(int $userId): bool
    {
        return $this->db->connection()->table('sync_backfill_state')
            ->where('user_id', $userId)
            ->whereNotNull('completed_at')
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
        $moved = [
            'cursor_table' => $table,
            'cursor_pk' => (string) $pk,
            'updated_at' => Instant::zulu($this->clock->now()),
        ];

        // A chunk that wrote nothing is not progress. Clearing the count on it
        // would let a table no keyring can read be retried until the install
        // is deleted, which is the condition the count exists to end.
        if ($captured > 0) {
            $moved['failed_slices'] = 0;
        }

        $this->db->connection()->table('sync_backfill_state')
            ->where('user_id', $userId)
            ->increment('captured', $captured, $moved);
    }

    // A slice that could not finish leaves the walk OWED. Stamping completed_at
    // here is what turned a three-second lock into a permanent hole: no later
    // slice re-walks a capture the state row says has already ended.
    public function recordFailure(int $userId): void
    {
        $this->db->connection()->table('sync_backfill_state')
            ->where('user_id', $userId)
            ->increment('failed_slices', 1, ['updated_at' => Instant::zulu($this->clock->now())]);
    }

    // Sends the next slice back to the top of the order. The cursor is an
    // optimisation over row-wise idempotence, so dropping it costs two indexed
    // reads per already-captured row and buys back every table the walk skipped.
    public function rewind(int $userId): void
    {
        $this->db->connection()->table('sync_backfill_state')
            ->where('user_id', $userId)
            ->update([
                'cursor_table' => null,
                'cursor_pk' => null,
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
