<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Events;

// Fires once per refreshed series per sweep (not per occurrence) after a
// recurring_series row's metric columns are updated by the detector;
// carries the post-refresh snapshot inline so listeners can decide whether
// to act without re-reading the row.
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
