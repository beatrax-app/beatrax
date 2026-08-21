<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Notifications\Public\Enums\NotificationState;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Modules\Sync\Public\Events\NotificationMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

final class NotificationWriter
{
    public function __construct(
        private readonly DeterministicKeyDeriver $keys,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
    ) {}

    public function write(NotificationDraft $draft): string
    {
        $id = $this->keys->derive($draft->userId, $draft->triggerType, $draft->subjectKey, $draft->occurrence);
        $now = $this->clock->now()->toDateTimeString();
        $params = $draft->params ?? [];
        if ($draft->copy !== null) {
            $params[NotificationCopySpec::PARAMS_KEY] = $draft->copy->toArray();
        }
        $paramsJson = $params === []
            ? null
            : json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $attrs = [
            'id' => $id,
            'user_id' => $draft->userId,
            'state' => NotificationState::Open->value,
            'read_at' => null,
            'dismissed_at' => null,
            'title' => $draft->title,
            'body' => $draft->body,
            'params' => $paramsJson,
            'trigger_type' => $draft->triggerType,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $encrypted = $this->codec->encryptAttrs('notifications', $attrs, $draft->userId, ($this->session)());

        $affected = $this->db->connection()->table('notifications')->insertOrIgnore($encrypted);

        if ($affected === 1) {
            $this->dispatchCreated($id, $draft, $paramsJson);
        }

        return $id;
    }

    private function dispatchCreated(string $id, NotificationDraft $draft, ?string $paramsJson): void
    {
        $this->events->dispatch(new NotificationMutated(
            notificationId: $id,
            userId: $draft->userId,
            mutationType: 'create',
            dirtyFields: [
                'title' => $draft->title,
                'body' => $draft->body,
                'trigger_type' => $draft->triggerType,
                'params' => $paramsJson,
            ],
        ));

        $this->events->dispatch(new NotificationDeliverable(
            notificationId: $id,
            userId: $draft->userId,
            triggerType: $draft->triggerType,
            title: $draft->title,
            body: $draft->body,
            deepLinkRoute: $draft->deepLinkRoute,
        ));
    }
}
