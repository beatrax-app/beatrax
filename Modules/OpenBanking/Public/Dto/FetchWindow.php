<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Dto;

use Carbon\CarbonImmutable;

/**
 * The date range a `RemoteSourceAdapter::fetch()` call requests from the
 * aggregator — inclusive on both ends. Both fields are `CarbonImmutable`
 * dates (time-of-day is not meaningful for a booked-transactions window).
 */
final readonly class FetchWindow
{
    public function __construct(
        public CarbonImmutable $dateFrom,
        public CarbonImmutable $dateTo,
    ) {}
}
