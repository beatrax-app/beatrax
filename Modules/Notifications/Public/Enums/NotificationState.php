<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Enums;

// The lifecycle of a notifications row: `open` until acted on, then the
// terminal `resolved`. The column stays string; this enum owns the
// vocabulary and the one legal edge (open -> resolved) the state machine
// guards.
/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
enum NotificationState: string
{
    case Open = 'open';

    case Resolved = 'resolved';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Resolved],
            self::Resolved => [],
        };
    }
}
