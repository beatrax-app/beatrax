<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Modules\Desktop\Public\Events\NotificationDeepLink;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use Native\Desktop\Facades\Notification;

// The Notifications module decides WHAT to notify and persists the row first;
// this listener only decides whether and how the OS shows it.
final readonly class DispatchOsNotification
{
    public function __construct(
        private WindowFocusState $focus,
        private SuppressionEvaluator $suppression,
        private Clock $clock,
    ) {}

    public function handleNotificationDeliverable(NotificationDeliverable $event): void
    {
        $decision = $this->suppression->shouldDeliver($event->userId, $event->triggerType, $this->clock->now());

        if (! $decision->deliver) {
            return;
        }

        if (! $this->shouldFire()) {
            return;
        }

        $body = $decision->hideDetails ? Lang::get('desktop::native.notification.hidden_details_body') : $event->body;

        $this->fire($event->title, $body, $event->deepLinkRoute);
    }

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
