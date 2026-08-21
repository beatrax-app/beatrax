<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

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
        public ?NotificationCopySpec $copy = null,
    ) {}

    // Title and body are rendered here as well as kept as keys: the OS push
    // fires once, in the recipient's language at that moment, and a device
    // still on the previous release reads the columns rather than the spec.
    /**
     * @param  array<string, mixed>|null  $params
     */
    public static function fromCopy(
        int $userId,
        string $triggerType,
        string $subjectKey,
        string $occurrence,
        NotificationCopySpec $copy,
        ?array $params = null,
        ?string $deepLinkRoute = null,
    ): self {
        return new self(
            userId: $userId,
            triggerType: $triggerType,
            subjectKey: $subjectKey,
            occurrence: $occurrence,
            title: $copy->title(),
            body: $copy->body(),
            params: $params,
            deepLinkRoute: $deepLinkRoute,
            copy: $copy,
        );
    }
}
