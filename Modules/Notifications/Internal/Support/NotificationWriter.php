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
use Modules\Sync\Public\Exceptions\SensitiveColumnKeyUnavailableException;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/mobile/background-sync-cannot-hold-the-key.md#the-scheduled-passes-that-cannot-write-either
 */
final class NotificationWriter
{
    // One alarm per user per process, for the same reason the codec keeps one:
    // a pass covering every category of a budget would otherwise file a line
    // per withheld row, and the answer is identical for all of them.
    /** @var array<int, true> */
    private array $deferralsAlarmed = [];

    public function __construct(
        private readonly DeterministicKeyDeriver $keys,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
        private readonly LoggerInterface $log,
    ) {}

    public function write(NotificationDraft $draft): NotificationWriteResult
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
            'trigger_type' => $draft->triggerType->value,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            $encrypted = $this->codec->encryptAttrs('notifications', $attrs, $draft->userId, ($this->session)());
        } catch (SensitiveColumnKeyUnavailableException) {
            // Not an error and not a duplicate, and it used to read as both: the
            // eight Persist* listeners logged the refusal at ERROR while the job
            // around them still reported processed. The content is derivable, so
            // the honest answer is to say it was withheld and hand that back.
            $this->alarmDeferral($draft->userId);

            return NotificationWriteResult::deferred($id);
        }

        $affected = $this->db->connection()->table('notifications')->insertOrIgnore($encrypted);

        if ($affected !== 1) {
            return NotificationWriteResult::duplicate($id);
        }

        $this->dispatchCreated($id, $draft, $paramsJson);

        return NotificationWriteResult::written($id);
    }

    private function alarmDeferral(int $userId): void
    {
        if (isset($this->deferralsAlarmed[$userId])) {
            return;
        }

        $this->deferralsAlarmed[$userId] = true;

        $this->log->warning(
            'NotificationWriter: withheld a notification this process cannot seal; it will be re-derived once a key is held.',
            ['userId' => $userId],
        );
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
                'trigger_type' => $draft->triggerType->value,
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
