<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Listeners;

use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use NativePHP\LocalNotifications\Facades\LocalNotifications;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
class DispatchMobileNotification
{
    public function __construct(
        private readonly SuppressionEvaluator $suppression,
        private readonly Clock $clock,
    ) {}

    // Subscriber for NotificationDeliverable - the one event both
    // platform delivery adapters (Desktop and Mobile) consume.
    public function handleNotificationDeliverable(NotificationDeliverable $event): void
    {
        $decision = $this->suppression->shouldDeliver($event->userId, $event->triggerType, $this->clock->now());

        if (! $decision->deliver) {
            return;
        }

        // Detail-free fallback body used when the device's hide-details
        // preference is on. Identical string to Desktop's.
        $body = $decision->hideDetails
            ? Lang::get('mobile::messages.notification_hidden_details')
            : $event->body;

        $this->fire($event->notificationId, $event->title, $body, $event->deepLinkRoute);
    }

    // class_exists()-guarded: under the repo-root toolchain (every CI
    // machine, every non-mobile test run) the plugin class is absent and
    // this is a silent no-op, never a fatal error.
    protected function fire(string $notificationId, string $title, string $body, ?string $deepLinkRoute): void
    {
        if (! class_exists(LocalNotifications::class)) {
            return;
        }

        $payload = array_filter([
            'id' => $notificationId,
            'title' => $title,
            'body' => $body,
            'url' => $deepLinkRoute,
        ], fn (?string $value): bool => $value !== null);

        // showRaw()'s mixed return is intentionally discarded - the
        // facade's return type is statically unresolvable under this
        // toolchain, so nothing further is chained off the result.
        LocalNotifications::showRaw($payload);
    }
}
