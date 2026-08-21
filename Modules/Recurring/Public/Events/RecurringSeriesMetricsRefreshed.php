<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Events;

final readonly class RecurringSeriesMetricsRefreshed
{
    public function __construct(
        public int $userId,
        public int $recurringSeriesId,
        public string $direction,
        public string $cadence,
        public int $latestAmountMinor,
        public string $latestCurrency,
    ) {}
}
