<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Read-side projection of a single `notifications` row for the /notifications
 * inbox (18-12) and any future consumer. `glyph`/`typeWord` are resolved at
 * the query layer via `NotificationCopy::typeChip()` so no render-time caller
 * has to repeat the trigger-type-to-chip mapping.
 *
 * `deepLinkDisabled`/`targetKind` default to `false`/`null` here — this plan
 * only carries the fields; plan 18-12's render-time existence check (D-25)
 * is what actually populates them for the dead-link row treatment.
 */
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

    /** Req 13 — the resolved/withdrawn axis, driven exclusively by NotificationStateMachine. */
    public function resolved(): bool
    {
        return $this->state === 'resolved';
    }

    /** Human-relative timestamp for the row's meta line (e.g. "2 hours ago"). */
    public function relativeTime(): string
    {
        return $this->createdAt->diffForHumans();
    }
}
