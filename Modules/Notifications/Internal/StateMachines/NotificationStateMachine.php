<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\StateMachines;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use RuntimeException;

/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
final class NotificationStateMachine
{
    use CoercesScalars;

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
}
