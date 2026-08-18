<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Listeners;

use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use NativePHP\LocalNotifications\Facades\LocalNotifications;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
class DispatchMobileNotification
{
    public function __construct(
        private readonly SuppressionEvaluator $suppression,
        private readonly Clock $clock,
        private readonly LoggerInterface $log,
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

        // Not discarded any more: showRaw() answers null when the bridge
        // function is absent, which left a notification stored and silently
        // undelivered, with nothing anywhere saying so.
        $result = LocalNotifications::showRaw($payload);

        if ($result === null || $result === false) {
            $this->log->warning('Mobile notification was not delivered: the native bridge returned nothing.', [
                'notification_id' => $notificationId,
                'bridge_available' => function_exists('nativephp_call'),
            ]);

            return;
        }

        $this->log->info('Mobile notification handed to the native bridge.', [
            'notification_id' => $notificationId,
            'result' => is_string($result) ? mb_substr($result, 0, 200) : gettype($result),
        ]);
    }
}
