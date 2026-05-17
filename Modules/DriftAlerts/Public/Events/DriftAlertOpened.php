<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Events;

/**
 * Dispatched after a drift_alerts row is inserted with state='open'.
 *
 * Carries the alert primary key, the originating series id, the signed
 * delta + annualized impact, and the original-currency code so any
 * downstream listener can render direction-aware copy or feed
 * forecasting models without re-reading the row.
 */
final readonly class DriftAlertOpened
{
    public function __construct(
        public int $userId,
        public int $driftAlertId,
        public int $recurringSeriesId,
        public string $direction,
        public int $deltaMinor,
        public int $annualizedImpactMinor,
        public string $currency,
    ) {}
}
