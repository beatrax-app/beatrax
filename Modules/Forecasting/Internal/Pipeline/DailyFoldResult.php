<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

final readonly class DailyFoldResult
{
    /**
     * @param  array<string, array{date: string, low_minor: int, point_minor: int, high_minor: int, currency: string}>  $points
     * @param  list<string>  $unconvertedCurrencies  codes left out of $points for want of a rate
     */
    public function __construct(
        public array $points,
        public array $unconvertedCurrencies = [],
    ) {}
}
