<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Spatie\LaravelData\Data;

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
