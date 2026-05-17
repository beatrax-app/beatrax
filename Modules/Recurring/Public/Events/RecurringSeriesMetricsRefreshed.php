<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Events;

/**
 * Dispatched after a recurring_series row's metric columns
 * (latest_amount_minor, latest_currency, monthly_equivalent_minor,
 * next_expected_at, cadence) are refreshed by the sweep detector.
 * Fires once per refreshed series per sweep — not per occurrence.
 *
 * Carries the post-refresh metric snapshot inline so listeners can
 * decide whether to do additional work without re-reading the row.
 */
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
