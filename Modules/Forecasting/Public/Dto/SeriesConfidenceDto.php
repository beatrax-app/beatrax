<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One row in the per-series confidence legend on the /forecast page.
 *
 * `confidence` is the categorical bucket the series falls into
 * (`high` / `medium` / `low`) based on the projector's variance
 * envelope; `pointMinor` is the central per-occurrence amount and
 * `bandWidthMinor` is the envelope width (highMinor - lowMinor) at
 * the per-occurrence scale.
 */
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
