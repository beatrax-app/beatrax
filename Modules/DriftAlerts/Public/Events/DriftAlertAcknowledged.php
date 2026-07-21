<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Events;

use Carbon\CarbonImmutable;

final readonly class DriftAlertAcknowledged
{
    public function __construct(
        public int $userId,
        public int $driftAlertId,
        public CarbonImmutable $acknowledgedAt,
    ) {}
}
