<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Read-side projection of the "next expected charge" hint surfaced on
 * each recurring-series row.
 *
 * `nextExpectedAt` is null when the cadence is irregular or the series
 * does not have enough history to extrapolate. `confidenceLow` flips
 * true when the observed-interval standard deviation exceeds the
 * detector's stability threshold; surfaces render the value with a
 * calmer treatment in that case (no countdown, no chip). `stddevDays`
 * is exposed so a developer-mode debug view can show the underlying
 * inter-arrival jitter.
 */
final class NextExpectedChargeDto extends Data
{
    public function __construct(
        public readonly ?CarbonImmutable $nextExpectedAt,
        public readonly string $cadence,
        public readonly bool $confidenceLow,
        public readonly int $stddevDays,
    ) {}
}
