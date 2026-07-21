<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Events;

use Carbon\CarbonImmutable;

final readonly class DriftAlertSnoozed
{
    public function __construct(
        public int $userId,
        public int $driftAlertId,
        public CarbonImmutable $snoozedUntil,
    ) {}
}
