<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Events;

/**
 * Dispatched after the `DismissDriftAlertAsCancelled` action records
 * the user's intent that the underlying recurring series was
 * cancelled outside the app. The drift_alerts row transitions to
 * `dismissed_cancelled`; the recurring_series row is NEVER modified
 * by this action (`noRecurringSeriesWritesFromDriftAlerts` invariant).
 *
 * Carries `recurringSeriesId` so downstream forecasting (Phase 10) can
 * exclude the series from projections without re-reading the row.
 */
final readonly class DriftAlertDismissedCancelled
{
    public function __construct(
        public int $userId,
        public int $driftAlertId,
        public int $recurringSeriesId,
    ) {}
}
