<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Events\NotificationMutated;
use stdClass;

/**
 * Sets `read_at` — a ONE-WAY LATCH (D-09): the row is marked read only if
 * `read_at` is currently null. Re-marking (or a cross-user invocation) is a
 * silent no-op — there is deliberately NO mark-as-unread action anywhere in
 * this module.
 *
 * Every query carries an explicit `->where('user_id', ...)` because
 * `BelongsToUser`'s `UserScope` global scope does not fire in queue/console
 * context (T-18-16) and the sha256 PK is NOT an authorization boundary.
 *
 * Does not touch `notifications.state` — that column is
 * `NotificationStateMachine`'s exclusive concern.
 */
final class MarkNotificationRead
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
    ) {}

    public function __invoke(string $notificationId, User $user): void
    {
        $connection = $this->db->connection();

        /** @var stdClass|null $row */
        $row = $connection->table('notifications')
            ->where('id', $notificationId)
            ->where('user_id', $user->id)
            ->first();

        if ($row === null || $row->read_at !== null) {
            return;
        }

        $now = $this->clock->now()->toDateTimeString();

        $connection->table('notifications')
            ->where('id', $notificationId)
            ->where('user_id', $user->id)
            ->update(['read_at' => $now, 'updated_at' => $now]);

        $this->events->dispatch(new NotificationMutated(
            notificationId: $notificationId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['read_at' => $now],
        ));
    }
}
