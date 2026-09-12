<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation\Dto;

use Carbon\CarbonImmutable;

final readonly class NetWorthSeriesPoint
{
    /**
     * @param  array<int, string>  $excludedAccounts  account id => name, for accounts holding a balance line no rate could convert — keyed so a series can union them instead of adding the same account up once per bucket, and carrying the name because the exclusion names the accounts rather than counting them
     */
    public function __construct(
        public CarbonImmutable $date,
        public string $label,
        public int $totalMinor,
        public string $currency,
        public array $excludedAccounts = [],
    ) {}

    public function excludedCount(): int
    {
        return count($this->excludedAccounts);
    }
}
