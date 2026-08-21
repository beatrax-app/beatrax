<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Notifications\Public\Enums\NotificationState;
use Spatie\LaravelData\Data;

final class NotificationDto extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $triggerType,
        public readonly string $title,
        public readonly string $body,
        public readonly ?CarbonImmutable $readAt,
        public readonly ?CarbonImmutable $dismissedAt,
        public readonly string $state,
        public readonly CarbonImmutable $createdAt,
        public readonly ?string $deepLinkUrl,
        public readonly bool $deepLinkDisabled,
        public readonly ?string $targetKind,
        public readonly string $glyph,
        public readonly string $typeWord,
    ) {}

    public function resolved(): bool
    {
        return $this->state === NotificationState::Resolved->value;
    }

    public function relativeTime(): string
    {
        return $this->createdAt->diffForHumans();
    }
}
