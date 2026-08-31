<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;

// Amounts are signed (expense negative, income positive) and in the contribution's
// own currency. No rate travels with them: the router can still move one onto a
// funder account denominated in something else, so DailyFold is the first stage
// that knows both sides of the pair, and it resolves the rate there.
final readonly class ForecastContribution
{
    public function __construct(
        public CarbonImmutable $date,
        public int $pointMinor,
        public int $lowMinor,
        public int $highMinor,
        public string $currency,
        public int $seriesId,
        public int $accountId,
        // Read only by CadenceJitter, which runs after every stage that selects
        // occurrences: a replica reaching one of those would be counted as an
        // occurrence in its own right.
        public bool $dateIsUncertain = false,
    ) {}
}
