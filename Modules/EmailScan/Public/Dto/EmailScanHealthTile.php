<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use Spatie\LaravelData\Data;

// Payload for the dashboard "Email scan health" tile: lines carries
// up to three InboxHealthLine entries, overflowCount is the count
// beyond the first three, and overallStatus mirrors the tile-level
// dot (healthy/stale/reauth).
/**
 * @phpstan-type LinesArray array<int, InboxHealthLine>
 */
final class EmailScanHealthTile extends Data
{
    /**
     * @param  array<int, InboxHealthLine>  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly string $overallStatus,
        public readonly int $overflowCount,
    ) {}
}
