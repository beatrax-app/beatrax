<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

// Which holds a pass is still owed an answer for. The watermark it reads is
// stamped against key material and this build's reach, so it can only speak
// for the holds those two undo; a hold cleared by a row ARRIVING is bounded by
// the log instead, and is owed another look the moment the log has moved.
/**
 * @link ../../../../.docs/features/sync/what-the-quarantine-tells-the-reader.md#a-window-stamped-against-a-key-cannot-bound-a-hold-a-key-never-held
 */
final readonly class QuarantinePassWindow
{
    public function __construct(private DatabaseManager $db) {}

    // Stated once and applied to the rows a pass replays AND the holds it
    // retires: two spellings of one window is how a pass comes to retire a
    // hold it never gave an answer to.
    public function narrow(Builder $query, int $userId, string $since): Builder
    {
        if (! $this->logMovedSince($userId, $since)) {
            return $query->where('created_at', '>', $since);
        }

        return $query->where(static function (Builder $window) use ($since): void {
            $window->where('created_at', '>', $since)
                ->orWhereIn('reason', QuarantineReason::stateRecoverable());
        });
    }

    // Anything this device recorded since the last pass, its own writes
    // included: a parent lands as an entry whether a peer sent it or the
    // reader typed it. False in the steady state, and a pass stamps its own
    // watermark, so one arrival buys one pass rather than one per request.
    private function logMovedSince(int $userId, string $since): bool
    {
        return $this->db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('recorded_at', '>', $since)
            ->exists();
    }
}
