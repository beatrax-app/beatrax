<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

final class DiscoveredSenderDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $inboxId,
        public readonly string $senderEmail,
        public readonly ?string $senderName,
        public readonly int $occurrenceCount,
        public readonly DateTimeImmutable $lastSeenAt,
        public readonly string $state,
    ) {}
}
