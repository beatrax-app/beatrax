<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

/**
 * One line on the dashboard's email-scan-health tile — a single
 * connected inbox's compact representation.
 *
 * Lives next to EmailScanHealthTile so the tile's `lines` array has
 * a typed shape rather than an ad-hoc array. Stores the email's
 * local part (the part before the '@') instead of the full address
 * so the tile copy stays compact for the typical three-line layout.
 */
final class InboxHealthLine extends Data
{
    public function __construct(
        public readonly string $provider,
        public readonly string $emailLocalPart,
        public readonly ?DateTimeImmutable $lastScanAt,
        public readonly string $status,
    ) {}
}
