<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Spatie\LaravelData\Data;

final class SeriesConfidenceDto extends Data
{
    public function __construct(
        public readonly int $seriesId,
        public readonly string $seriesName,
        public readonly string $confidence,
        public readonly int $pointMinor,
        public readonly int $bandWidthMinor,
        public readonly string $currency,
    ) {}
}
