<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation\Dto;

use Carbon\CarbonImmutable;

final readonly class NetWorthSeriesPoint
{
    /**
     * @param  list<int>  $excludedAccountIds  Which accounts held a balance line no rate could convert — the identities, not a tally, so a series can union them instead of adding the same account up once per bucket
     */
    public function __construct(
        public CarbonImmutable $date,
        public string $label,
        public int $totalMinor,
        public string $currency,
        public array $excludedAccountIds = [],
    ) {}

    public function excludedCount(): int
    {
        return count($this->excludedAccountIds);
    }
}
