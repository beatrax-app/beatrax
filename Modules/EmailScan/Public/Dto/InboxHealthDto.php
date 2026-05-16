<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

/**
 * Single-inbox health snapshot served to the /inboxes page table and
 * (via the dashboard tile aggregator) the at-a-glance status surface.
 *
 * Mirrors the joined shape of inboxes + inbox_scan_state with
 * resolved scalars: the timestamp + status come from inbox_scan_state;
 * the email + provider + backfill window come from inboxes; the
 * optional backfill counters carry the JSON progress payload's
 * fetched_count + total_estimated when an active backfill is in
 * flight. Both backfill counters are null when no backfill is
 * currently running so the UI can branch on absence rather than on
 * sentinel zero values.
 */
final class InboxHealthDto extends Data
{
    public function __construct(
        public readonly int $inboxId,
        public readonly int $userId,
        public readonly string $provider,
        public readonly string $email,
        public readonly int $backfillWindowMonths,
        public readonly ?DateTimeImmutable $lastScanAt,
        public readonly string $status,
        public readonly int $retryAttempts,
        public readonly ?string $errorMessage,
        public readonly ?int $backfillFetchedCount,
        public readonly ?int $backfillTotalEstimated,
    ) {}
}
