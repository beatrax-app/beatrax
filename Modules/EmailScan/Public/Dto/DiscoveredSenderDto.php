<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

/**
 * Single row from the discovered_senders panel feed.
 *
 * Surfaces a sender the daily DiscoveryScanJob saw at least
 * `DiscoveredSenderQuery::MIN_OCCURRENCES` times within the rolling
 * `DiscoveredSenderQuery::WITHIN_DAYS` window but the user has not
 * yet promoted to known_senders OR dismissed via the /inboxes panel.
 *
 * The `state` field carries the current row state from the schema's
 * enum (`candidate` / `added` / `dismissed`); the DiscoveredSenderQuery
 * only returns rows with `state='candidate'` so consumers may rely on
 * that invariant when rendering Add / Dismiss chips.
 */
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
