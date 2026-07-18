<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Events;

/**
 * The ONE event both platform delivery adapters (Desktop's `DispatchOsNotification`
 * repointed in 18-11, Mobile's local-notifications bridge in 18-15) subscribe to
 * (D-31/D-32). Dispatched by `NotificationWriter` ONLY after the `notifications`
 * row's insert has committed (D-28 / WR-06) — never from inside an open
 * transaction, and never for a duplicate (re-derived) write.
 *
 * Carries everything an adapter needs to render a banner: the deliverable's
 * $title/$body ride the event as plaintext VALUES, not a re-read of the
 * (possibly encrypted-at-rest) `notifications` row — so no adapter needs a
 * KEK, a DB read, or the app-lock to be unlocked at delivery time (T-18-17).
 * The event is dispatched and consumed inside one Laravel event-bus tick,
 * locally — it never crosses a process or network boundary.
 */
final readonly class NotificationDeliverable
{
    public function __construct(
        public string $notificationId,
        public int $userId,
        public string $triggerType,
        public string $title,
        public string $body,
        public ?string $deepLinkRoute,
    ) {}
}
