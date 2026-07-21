<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation\Dto;

use Carbon\CarbonImmutable;

/**
 * @link ../../../../../.docs/features/reports/architecture.md
 */
final readonly class NetWorthSeriesPoint
{
    public function __construct(
        public CarbonImmutable $date,
        public string $label,
        public int $totalMinor,
        public string $currency,
        public int $excludedCount,
    ) {}
}
