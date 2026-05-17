<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Dto;

use DateTimeImmutable;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Spatie\LaravelData\Data;

/**
 * Unified input shape for the matcher dispatch loop.
 *
 * The consumer job iterates both `inbox_messages` rows
 * (`source='inbox'`, `providerMessageId` from Gmail/Graph) and
 * `file_imports` rows (`source='file-drop'`, `providerMessageId`
 * from the RFC 822 Message-ID header or its sha256 fallback). The
 * matcher contract speaks `InboxMessageDto` so rows are bridged
 * here via `toInboxMessageDto()` — `inboxId` is synthesised as `0`
 * because matchers never branch on it and the file-drop path has no
 * remote inbox to reference.
 *
 * Matchers receive this DTO; they read `senderEmail` + `subject` for
 * the cheap `canHandle()` filter and the on-disk `emlPath` only
 * indirectly via the dispatch caller (the dispatcher loads the raw
 * `.eml` bytes once and passes them straight to `match()`).
 */
final class MatcherInputDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $source,
        public readonly string $providerMessageId,
        public readonly string $senderEmail,
        public readonly ?string $senderName,
        public readonly ?string $subject,
        public readonly DateTimeImmutable $internalDate,
        public readonly string $emlPath,
    ) {}

    /**
     * Adapt to the `InboxMessageDto` shape the SenderMatcher contract
     * expects. `inboxId` is always synthesised as `0` because matchers
     * never branch on it; the field exists on InboxMessageDto purely
     * for audit-trail use by the inbox-handoff caller, and file-drop
     * rows have no remote inbox to point at.
     */
    public function toInboxMessageDto(): InboxMessageDto
    {
        return new InboxMessageDto(
            id: $this->id,
            userId: $this->userId,
            inboxId: 0,
            providerMessageId: $this->providerMessageId,
            internalDate: $this->internalDate,
            senderEmail: $this->senderEmail,
            senderName: $this->senderName,
            subject: $this->subject,
            status: 'fetched',
            fetchedAt: $this->internalDate,
        );
    }
}
