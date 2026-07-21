<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Events;

final readonly class DriftAlertDismissedCancelled
{
    /**
     * @param  int  $recurringSeriesId  the recurring_series row is never modified by this
     *                                  action (noRecurringSeriesWritesFromDriftAlerts invariant); carried so downstream
     *                                  listeners can exclude the series from their projections without re-reading the
     *                                  drift_alerts row
     */
    public function __construct(
        public int $userId,
        public int $driftAlertId,
        public int $recurringSeriesId,
    ) {}
}
