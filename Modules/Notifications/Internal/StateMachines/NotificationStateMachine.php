<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\StateMachines;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\StateMachine\InvalidStateTransitionException;
use Modules\Notifications\Public\Enums\NotificationState;

final class NotificationStateMachine
{
    use CoercesScalars;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function resolve(string $notificationId, int $userId): void
    {
        $this->db->connection()->transaction(function () use ($notificationId, $userId): void {
            $connection = $this->db->connection();

            $row = $connection->table('notifications')
                ->where('id', $notificationId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new NotificationNotFoundException(
                    "NotificationStateMachine: notifications row {$notificationId} not found for user {$userId}.",
                );
            }

            $currentState = self::toString($row->state);
            $this->guardTransition($notificationId, $currentState, NotificationState::Resolved->value);

            $connection->table('notifications')
                ->where('id', $notificationId)
                ->where('user_id', $userId)
                ->update([
                    'state' => NotificationState::Resolved->value,
                    'updated_at' => $this->clock->now()->toDateTimeString(),
                ]);
        });
    }

    private function guardTransition(string $notificationId, string $currentState, string $toState): void
    {
        $current = NotificationState::tryFrom($currentState);
        $allowed = $current === null
            ? []
            : array_map(static fn (NotificationState $next): string => $next->value, $current->allowedNext());
        if (! in_array($toState, $allowed, strict: true)) {
            throw InvalidStateTransitionException::forTransition('notifications', $notificationId, $currentState, $toState);
        }
    }
}
