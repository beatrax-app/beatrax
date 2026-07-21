<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Modules\Sync\Public\Events\NotificationMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
final class NotificationWriter
{
    public function __construct(
        private readonly DeterministicKeyDeriver $keys,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    /**
     * @param  array<string, mixed>|null  $params
     */
    public function write(
        int $userId,
        string $triggerType,
        string $subjectKey,
        string $occurrence,
        string $title,
        string $body,
        ?array $params = null,
        ?string $deepLinkRoute = null,
    ): string {
        $id = $this->keys->derive($userId, $triggerType, $subjectKey, $occurrence);
        $now = $this->clock->now()->toDateTimeString();
        $paramsJson = $params !== null ? json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null;

        $attrs = [
            'id' => $id,
            'user_id' => $userId,
            'state' => 'open',
            'read_at' => null,
            'dismissed_at' => null,
            'title' => $title,
            'body' => $body,
            'params' => $paramsJson,
            'trigger_type' => $triggerType,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $encrypted = $this->codec->encryptAttrs('notifications', $attrs, $userId, $this->session);

        $affected = $this->db->connection()->table('notifications')->insertOrIgnore($encrypted);

        if ($affected === 1) {
            $this->events->dispatch(new NotificationMutated(
                notificationId: $id,
                userId: $userId,
                mutationType: 'create',
                dirtyFields: [
                    'title' => $title,
                    'body' => $body,
                    'trigger_type' => $triggerType,
                    'params' => $paramsJson,
                ],
            ));

            $this->events->dispatch(new NotificationDeliverable(
                notificationId: $id,
                userId: $userId,
                triggerType: $triggerType,
                title: $title,
                body: $body,
                deepLinkRoute: $deepLinkRoute,
            ));
        }

        return $id;
    }
}
