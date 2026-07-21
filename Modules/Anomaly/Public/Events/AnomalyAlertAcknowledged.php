<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Events;

use Carbon\CarbonImmutable;

final readonly class AnomalyAlertAcknowledged
{
    public function __construct(
        public int $userId,
        public int $anomalyAlertId,
        public CarbonImmutable $acknowledgedAt,
    ) {}
}
