<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One day's projection point in a per-account forecast.
 *
 * `lowMinor`, `pointMinor`, `highMinor` are the lower-band, central,
 * and upper-band balances for the day in minor units. The chart panel
 * renders the (low, high) tuple as the confidence band and the point
 * as the central line. All three are denominated in the carrying
 * `currency` (ISO 4217).
 */
final class ForecastPointDto extends Data
{
    public function __construct(
        public readonly string $date,
        public readonly int $lowMinor,
        public readonly int $pointMinor,
        public readonly int $highMinor,
        public readonly string $currency,
    ) {}
}
