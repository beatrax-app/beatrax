<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
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
