<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Payload for the dashboard "Email scan health" tile.
 *
 * `lines` carries up to three InboxHealthLine entries — one per
 * connected inbox in display order. `overflowCount` is the number of
 * connected inboxes beyond the first three (zero when the user has
 * three or fewer). `overallStatus` mirrors the tile-level status dot:
 * 'healthy' when every inbox is healthy, 'stale' when at least one
 * inbox has not been scanned recently, 'reauth' when at least one
 * inbox needs the user to reconnect.
 *
 * The dashboard hides the tile entirely when the query method that
 * builds this DTO returns null (no connected inboxes); the DTO itself
 * never serialises a "no inboxes" sentinel — absence becomes "no
 * tile" upstream.
 *
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
