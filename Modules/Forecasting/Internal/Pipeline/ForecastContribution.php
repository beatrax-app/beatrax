<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;

// The (lowMinor, pointMinor, highMinor) triple is signed and in the
// contribution's native currency (expense series negative, income
// series positive); DailyFold converts to the account's default
// currency using fxRateUsed before combining per day.
final readonly class ForecastContribution
{
    public function __construct(
        public CarbonImmutable $date,
        public int $pointMinor,
        public int $lowMinor,
        public int $highMinor,
        public string $currency,
        public ?float $fxRateUsed,
        public int $seriesId,
        public int $accountId,
    ) {}
}
