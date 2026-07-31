<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
final readonly class NotificationDraft
{
    /**
     * @param  array<string, mixed>|null  $params
     */
    public function __construct(
        public int $userId,
        public string $triggerType,
        public string $subjectKey,
        public string $occurrence,
        public string $title,
        public string $body,
        public ?array $params = null,
        public ?string $deepLinkRoute = null,
    ) {}
}
