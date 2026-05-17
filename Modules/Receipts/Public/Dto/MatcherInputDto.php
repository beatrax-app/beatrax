<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Dto;

use DateTimeImmutable;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Spatie\LaravelData\Data;

/**
 * Unified input shape for the matcher dispatch loop.
 *
 * The consumer job iterates both Phase 6 `inbox_messages` rows
 * (`source='inbox'`, `providerMessageId` from Gmail/Graph) and the
 * Phase 7 `file_imports` rows (`source='file-drop'`,
 * `providerMessageId` from the RFC 822 Message-ID header or its
 * sha256 fallback). The matcher contract speaks `InboxMessageDto`
 * so file-drop rows are bridged here via `toInboxMessageDto()`
 * (synthesising `inboxId=0` to mark the absence of a remote inbox).
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
     * expects. `inboxId` is synthesised as `0` for file-drop rows
     * because they never originated from a remote inbox; matchers
     * never branch on `inboxId` (it is purely an audit-trail field).
     */
    public function toInboxMessageDto(): InboxMessageDto
    {
        return new InboxMessageDto(
            id: $this->id,
            userId: $this->userId,
            inboxId: $this->source === 'inbox' ? 0 : 0,
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
