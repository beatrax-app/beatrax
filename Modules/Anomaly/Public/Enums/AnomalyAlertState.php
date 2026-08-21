<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Enums;

enum AnomalyAlertState: string
{
    case Open = 'open';

    case Acknowledged = 'acknowledged';

    case Snoozed = 'snoozed';

    case Dismissed = 'dismissed';

    // `dismissed => [Open]` is the undo edge that diverges from drift-alerts'
    // otherwise identical map; only RemoveAnomalySuppressionRule uses it.
    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Acknowledged, self::Snoozed, self::Dismissed],
            self::Acknowledged => [],
            self::Snoozed => [self::Open, self::Acknowledged, self::Dismissed],
            self::Dismissed => [self::Open],
        };
    }
}
