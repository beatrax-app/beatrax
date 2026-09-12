<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Modules\FX\Public\Dto\RateSet;

final readonly class DailyFoldResult
{
    /**
     * @param  array<string, array{date: string, low_minor: int, point_minor: int, high_minor: int, currency: string}>  $points
     * @param  list<string>  $unconvertedCurrencies  codes left out of $points for want of a rate
     * @param  RateSet  $rates  narrowed to the currencies this fold actually converted, so the stored run discloses the rates behind ITS curve and not every pair the run's batched lookup happened to read
     */
    public function __construct(
        public array $points,
        public array $unconvertedCurrencies,
        public RateSet $rates,
    ) {}
}
