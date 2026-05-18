<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Read API payload for one per-account projection on the /forecast
 * page.
 *
 * `points` is the ordered list of per-day forecast points across the
 * horizon (length = horizonDays + 1, including the as-of anchor day).
 * `seriesConfidence` is the legend the chart panel renders alongside
 * the band. `isComputing` drives the `opacity-60` overlay applied
 * during background projection runs.
 *
 * `scenarioId` is null for the baseline projection and the saved
 * scenario id for an alternative projection.
 */
final class ForecastDto extends Data
{
    /**
     * @param  array<int, ForecastPointDto>  $points
     * @param  array<int, SeriesConfidenceDto>  $seriesConfidence
     */
    public function __construct(
        public readonly int $accountId,
        public readonly string $accountName,
        public readonly string $defaultCurrency,
        public readonly int $horizonDays,
        public readonly ?int $scenarioId,
        public readonly CarbonImmutable $asOf,
        public readonly int $todayBalanceMinor,
        public readonly array $points,
        public readonly array $seriesConfidence,
        public readonly bool $isComputing,
    ) {}
}
