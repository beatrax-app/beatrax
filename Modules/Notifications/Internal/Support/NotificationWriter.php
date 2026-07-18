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
 * The internal collaborator every trigger `Persist*` listener (plans
 * 18-06..18-11) calls to persist a notification. This is the ONE call site
 * outside `DeterministicKeyDeriver` itself that derives a notification id
 * (D-38 invariant 3).
 *
 * Idempotent by construction: `insertOrIgnore` on the deterministic PK means
 * writing the same `(user_id, trigger_type, subject_key, occurrence)` tuple
 * twice yields exactly one row (D-05, Req 1) and — because the deliverable
 * + mutation events are dispatched ONLY when the insert actually landed —
 * exactly one `NotificationDeliverable` / `NotificationMutated`, never two.
 *
 * `title`/`body`/`params`/`trigger_type` are the four `SensitiveFieldRegistry`-
 * registered columns (18-04); this writer encrypts them under the current GDK
 * epoch via `SensitiveColumnCodec::encryptAttrs()` before the row ever touches
 * disk — a pass-through no-op when encryption is not enabled for the user,
 * mirroring `RecordTransactions`'s direct-write encryption hook exactly.
 * `id`/`user_id`/`state`/`read_at`/`dismissed_at` are deliberately excluded
 * from that registry (the PK is matched in `WHERE` dedup clauses and the
 * timestamps drive KEK-less unread counts / pruning), so the codec leaves
 * them untouched.
 *
 * Both events dispatch strictly AFTER the write has committed (D-28 / WR-06):
 * `insertOrIgnore` is a single autocommit statement, so by the time its
 * return value is inspected the write is already durable — no explicit
 * transaction wrapper is needed here for that guarantee to hold.
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
