<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Dto;

use DateTimeImmutable;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Spatie\LaravelData\Data;

// Unified input shape for the matcher dispatch loop, bridging both
// inbox_messages rows and file_imports rows into the one InboxMessageDto
// shape SenderMatcher expects.
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

    // inboxId is always synthesised as 0 — matchers never branch on
    // it, and a file-drop row has no remote inbox to point at.
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
