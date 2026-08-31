<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Modules\Forecasting\Internal\Enums\SeriesConfidence;
use Spatie\LaravelData\Data;

final class SeriesConfidenceDto extends Data
{
    /**
     * @param  int  $monthlyEquivalentMinor  what the series costs in a month.
     *                                       The legend line is suffixed "/mo",
     *                                       and it printed the latest CHARGE:
     *                                       EUR120.00/mo for EUR120.00 a year.
     */
    public function __construct(
        public readonly int $seriesId,
        public readonly string $seriesName,
        public readonly SeriesConfidence $confidence,
        public readonly int $pointMinor,
        public readonly int $monthlyEquivalentMinor,
        public readonly int $bandWidthMinor,
        public readonly string $currency,
    ) {}
}
