<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

// Single-inbox health snapshot for the /inboxes table and the
// dashboard tile aggregator: mirrors the joined inboxes +
// inbox_scan_state shape, with the backfill counters null (not zero)
// when no backfill is currently running so the UI branches on absence.
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
