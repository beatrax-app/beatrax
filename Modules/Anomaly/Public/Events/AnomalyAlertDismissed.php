<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Events;

// `dismissedAs` distinguishes a plain dismiss (`DismissAnomalyAlert`) from
// dismiss-as-expected (`DismissAnomalyAlertAsExpected`, which also writes
// a suppression rule) so listeners need not re-read the alert row.
final readonly class AnomalyAlertDismissed
{
    public function __construct(
        public int $userId,
        public int $anomalyAlertId,
        public string $dismissedAs,
    ) {}
}
