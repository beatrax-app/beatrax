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
 * @link ../../../../.docs/features/notifications/architecture.md
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
