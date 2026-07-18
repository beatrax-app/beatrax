<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Core\Public\Contracts\Clock;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Modules\Desktop\Public\Events\NotificationDeepLink;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use Native\Desktop\Facades\Notification;

/**
 * The sole desktop delivery adapter (D-31/D-32) — the ONLY listener on
 * `Modules\Notifications\Public\Events\NotificationDeliverable`. The prior
 * three per-category handlers have collapsed into this ONE handler: the
 * Notifications module now decides WHAT to notify (title/body/deep-link,
 * D-29 copy authority) and persists the row FIRST; this listener only
 * decides whether/how to DELIVER it to the OS.
 *
 * Delivery decision order:
 *
 *   1. `SuppressionEvaluator::shouldDeliver()` (D-38 invariant 4) — the
 *      single suppression site for per-trigger toggles + quiet hours + the
 *      D-43 seeding flag. The row is ALREADY persisted at this point (by
 *      `NotificationWriter`, before this event was even dispatched) — a
 *      suppressed decision means "stay quiet on this device", never
 *      "drop the row" (D-08 / Req 9 shared contract).
 *   2. The D-13 focus-gate — UNCHANGED: fires only when the window is
 *      unfocused. When focused, the in-app `SystemAlertsBanner` /
 *      notification inbox already surfaces the same information.
 *   3. D-24 hide-details: when the device's `hide_details` preference is
 *      on, the OS body is replaced with a detail-free fallback instead of
 *      the event's real body.
 *
 * Native-chrome carve-out: this listener calls the NativePHP `Notification`
 * facade directly (see the `use` import above). That facade is the only
 * reachable surface for NativePHP's notification API (no container-bound
 * alternative), so the file is on `BoundaryArchTest`'s facade allow-list +
 * `phpstan.neon` ignore list. The crossing stays confined to this one
 * listener and to `fire()` specifically — `SuppressionEvaluator` and
 * `Clock` are plain constructor-DI collaborators that touch no NativePHP
 * facade.
 *
 * Per-batch noise (Req 10 / D-22 — CLOSED): the prior per-row import
 * subscription that could fire hundreds of OS notifications for one large
 * import is gone. `Modules\Ledger`'s batch-altitude
 * `TransactionBatchImported` event now feeds `PersistCoalescedImport`,
 * which writes exactly ONE notification per import batch — this listener
 * never sees more than one deliverable event per batch, closing the debt
 * this class's docblock used to record as deferred.
 */
final class DispatchOsNotification
{
    /** Detail-free fallback body used when the device's D-24 hide-details preference is on. */
    private const HIDDEN_DETAILS_BODY = 'Open beatrax to see the details.';

    public function __construct(
        private readonly WindowFocusState $focus,
        private readonly SuppressionEvaluator $suppression,
        private readonly Clock $clock,
    ) {}

    /**
     * Subscriber for `NotificationDeliverable` — the ONE event both
     * platform delivery adapters (Desktop here, Mobile in 18-15) consume
     * (D-31/D-32).
     */
    public function handleNotificationDeliverable(NotificationDeliverable $event): void
    {
        $decision = $this->suppression->shouldDeliver($event->userId, $event->triggerType, $this->clock->now());

        if (! $decision->deliver) {
            return;
        }

        if (! $this->shouldFire()) {
            return;
        }

        $body = $decision->hideDetails ? self::HIDDEN_DETAILS_BODY : $event->body;

        $this->fire($event->title, $body, $event->deepLinkRoute);
    }

    /**
     * The D-13 focus-gate: an OS notification fires ONLY when the
     * window is unfocused. When focused, the in-app banner already
     * surfaces the event — no double-notification.
     */
    private function shouldFire(): bool
    {
        return ! $this->focus->isFocused();
    }

    private function fire(string $title, string $body, ?string $deepLinkRoute): void
    {
        Notification::title($title)
            ->message($body)
            ->event(NotificationDeepLink::class)
            ->reference($deepLinkRoute ?? '')
            ->show();
    }
}
