<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\FX\Public\Dto\ConversionDisclosure;
use Spatie\LaravelData\Data;

final class ForecastDto extends Data
{
    /**
     * @param  array<int, ForecastPointDto>  $points
     * @param  array<int, SeriesConfidenceDto>  $seriesConfidence
     * @param  list<string>  $unconvertedCurrencies  codes left out of the curve for
     *                                               want of a rate, named rather than folded in at one to one
     * @param  ?ConversionDisclosure  $conversion  the rates the curve converted at, read back out of the stored run beside the codes it could not price. Null where nothing was converted -- a flat line off booked rows, or a curve still computing. A run stored before the format carried rates projects the unrecorded state instead, which is not the same as either.
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
        // A failed run has no points, and the fallback line below it is drawn
        // from booked rows alone. Without this the reader cannot tell that flat
        // line from a projection that genuinely has nothing in it.
        public readonly bool $runFailed = false,
        // True where $asOf is behind today: the curve opens on the day the run
        // was computed, so an unrefreshed one draws the past under the word
        // "today". The scheduler had never dispatched, so every run was this.
        public readonly bool $isStale = false,
        public readonly array $unconvertedCurrencies = [],
        public readonly ?ConversionDisclosure $conversion = null,
    ) {}
}
