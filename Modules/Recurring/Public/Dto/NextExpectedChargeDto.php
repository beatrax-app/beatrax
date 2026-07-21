<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class NextExpectedChargeDto extends Data
{
    /**
     * @param  CarbonImmutable|null  $nextExpectedAt  null when the cadence is irregular or the
     *                                                series lacks enough history to extrapolate
     * @param  bool  $confidenceLow  true when the observed-interval standard deviation exceeds the
     *                               detector's stability threshold; surfaces render a calmer treatment (no countdown, no chip)
     * @param  int  $stddevDays  underlying inter-arrival jitter, for a developer-mode debug view
     */
    public function __construct(
        public readonly ?CarbonImmutable $nextExpectedAt,
        public readonly string $cadence,
        public readonly bool $confidenceLow,
        public readonly int $stddevDays,
    ) {}
}
