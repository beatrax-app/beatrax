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
    // `snoozed => [Snoozed]` is a real move, not a no-op: the Open tab lists a
    // snoozed row whose window has lapsed, so re-snoozing one is reachable.
    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Acknowledged, self::Snoozed, self::Dismissed],
            self::Acknowledged => [],
            self::Snoozed => [self::Open, self::Acknowledged, self::Dismissed, self::Snoozed],
            self::Dismissed => [self::Open],
        };
    }

    // Asked by every Public action before it transitions: a row the other tab
    // or the paired device already closed answers no, and the action returns
    // instead of letting the machine raise into the reader's face.
    public function allows(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }
}
