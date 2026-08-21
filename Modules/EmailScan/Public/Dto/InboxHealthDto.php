<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

// The backfill counters are null rather than zero when no backfill is running,
// so the UI branches on absence instead of on a count of nothing.
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
