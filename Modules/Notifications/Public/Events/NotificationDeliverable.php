<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Events;

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
