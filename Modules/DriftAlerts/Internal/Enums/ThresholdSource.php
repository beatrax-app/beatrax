<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Enums;

// Which of the three thresholds an alert was judged against. The value is
// frozen onto the drift_alerts row so the reader can be told what the alert
// was measured by, even after the setting moves under it.
enum ThresholdSource: string
{
    case SeriesOverride = 'series_override';

    case Global = 'global';

    case Default = 'default';
}
