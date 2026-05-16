<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

/**
 * Single row from inbox_messages, in the shape the downstream parser
 * stage iterates.
 *
 * `internalDate` carries the provider-stamped message time (Gmail's
 * `internalDate` or Graph's `receivedDateTime`); `fetchedAt` is the
 * local time the background fetcher persisted the .eml. The pair lets
 * the parser order messages by send-time while still being able to
 * rewind to "everything fetched since N" via fetchedAt.
 *
 * `status` is the lifecycle handoff column — Phase 6 only ever writes
 * 'fetched'; the parser stage transitions rows in place to 'parsed',
 * 'skipped', or 'unmatched'.
 */
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
