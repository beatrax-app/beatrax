<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

// Single row from inbox_messages, in the shape the downstream parser
// iterates. internalDate is the provider-stamped message time;
// fetchedAt is when the fetcher persisted the .eml. status is the
// lifecycle handoff — this module only ever writes 'fetched'.
final class InboxMessageDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $inboxId,
        public readonly string $providerMessageId,
        public readonly DateTimeImmutable $internalDate,
        public readonly string $senderEmail,
        public readonly ?string $senderName,
        public readonly ?string $subject,
        public readonly string $status,
        public readonly DateTimeImmutable $fetchedAt,
    ) {}
}
