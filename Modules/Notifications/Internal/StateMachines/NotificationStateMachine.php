<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\StateMachines;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use RuntimeException;

/**
 * The single legal mutator of `notifications.state`.
 *
 * LOCKED PLANNER DECISION (resolves RESEARCH.md Open Question 1 / Pitfall
 * 4, binding for the whole phase): `notifications.state` carries ONLY the
 * Req-13 resolved/withdrawn axis. `ALLOWED_TRANSITIONS` is exactly
 * `['open' => ['resolved'], 'resolved' => []]` — there is no "unread"
 * concept here and no "mark as unread" transition. `read_at` (D-09, a
 * plain nullable latch) and `dismissed_at` (D-10, a plain nullable
 * reversible timestamp) are written DIRECTLY by callers, never through
 * this class.
 *
 * `state` is a LOCALLY-DERIVED column and is NOT a synced field — it is
 * written only by this class, from a local settlement check (plan
 * 18-06). `OpLogReplayer` therefore NEVER touches `state`: it only
 * replays `read_at` / `dismissed_at`. This makes the D-39 "replayer is a
 * second mutator" wrinkle disappear for `state` — `OpLogReplayer` is a
 * legitimate second mutator of `read_at`/`dismissed_at` ONLY, and never
 * of `state`. Plan 18-17's single-mutator invariant asserts this class is
 * the ONLY writer of the `state` column.
 *
 * The schema-level `notifications_state_check_insert/update` trigger
 * pair (D-39) plus this class's `ALLOWED_TRANSITIONS` map enforce the
 * two-value enum at two layers: runtime (this class throws on an illegal
 * target) and database (SQLite ABORTs on any out-of-enum value even if
 * an arbitrary code path bypasses this class).
 *
 * Mirrors `AnomalyAlertStateMachine::transition()`'s shape: opens a DB
 * transaction, sets `PRAGMA busy_timeout = 5000`, takes a row lock via
 * `lockForUpdate()`, validates the requested move, and writes. Every
 * query carries an explicit `->where('user_id', $userId)` because
 * `BelongsToUser`'s `UserScope` global scope does not fire in queue or
 * console context (T-18-03).
 */
final class NotificationStateMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'open' => ['resolved'],
        'resolved' => [],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function resolve(string $notificationId, int $userId): void
    {
        $this->db->connection()->transaction(function () use ($notificationId, $userId): void {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

            $row = $connection->table('notifications')
                ->where('id', $notificationId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new RuntimeException(
                    "NotificationStateMachine: notifications row {$notificationId} not found for user {$userId}.",
                );
            }

            $currentState = self::toString($row->state);
            $this->guardTransition($notificationId, $currentState, 'resolved');

            $connection->table('notifications')
                ->where('id', $notificationId)
                ->where('user_id', $userId)
                ->update([
                    'state' => 'resolved',
                    'updated_at' => $this->clock->now()->toDateTimeString(),
                ]);
        });
    }

    private function guardTransition(string $notificationId, string $currentState, string $toState): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$currentState] ?? [];
        if (! in_array($toState, $allowed, strict: true)) {
            throw new RuntimeException(
                "Illegal notifications transition for id={$notificationId}: {$currentState} -> {$toState}",
            );
        }
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
