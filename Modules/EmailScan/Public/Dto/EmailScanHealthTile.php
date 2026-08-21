<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use Spatie\LaravelData\Data;

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
