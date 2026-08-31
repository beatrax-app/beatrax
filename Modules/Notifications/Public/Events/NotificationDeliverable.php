<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Events;

use Modules\Notifications\Public\Enums\NotificationTrigger;

final readonly class NotificationDeliverable
{
    public function __construct(
        public string $notificationId,
        public int $userId,
        public NotificationTrigger $triggerType,
        public string $title,
        public string $body,
        public ?string $deepLinkRoute,
    ) {}
}
