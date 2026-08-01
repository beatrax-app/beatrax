<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// The percentage steps a drift-alert threshold may take. Shared by the
// settings validator (Core owns the drift_alert_threshold_percent column),
// the per-series editor (DriftAlerts) and the per-series setter (Recurring)
// so the allow-list has one definition instead of four.
final class DriftThresholdOptions
{
    /** @var list<int> */
    public const array PERCENTS = [1, 2, 5, 10, 25, 50];
}
