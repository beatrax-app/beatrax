<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

// $internalDate is the provider's stamp, $fetchedAt ours. $status is the
// handoff to the parser; this module only ever writes 'fetched'.
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
