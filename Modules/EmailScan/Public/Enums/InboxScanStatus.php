<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Enums;

// The lifecycle of an inbox_scan_state.status row. The column, its DTOs
// and applyStatus()'s signature stay string; this enum is the one
// canonical spelling every caller maps through, and it owns the transition
// graph InboxScanStateMachine's guard enforces.
/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
enum InboxScanStatus: string
{
    case Idle = 'idle';

    case Backfilling = 'backfilling';

    case Scanning = 'scanning';

    case RateLimited = 'rate_limited';

    case NeedsReauth = 'needs_reauth';

    case Error = 'error';

    // Re-entrant self-edges keep a repeated poll from tripping the guard;
    // needs_reauth is near-terminal (only a re-consent returns it to idle);
    // error and idle can restart any scan. There is no "any -> any" escape.
    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Idle => [self::Idle, self::Backfilling, self::Scanning, self::NeedsReauth, self::Error],
            self::Backfilling => [self::Backfilling, self::Idle, self::RateLimited, self::NeedsReauth, self::Error],
            self::Scanning => [self::Scanning, self::Idle, self::RateLimited, self::NeedsReauth, self::Error],
            self::RateLimited => [self::Backfilling, self::Scanning, self::Idle, self::NeedsReauth, self::Error],
            self::NeedsReauth => [self::Idle, self::NeedsReauth],
            self::Error => [self::Idle, self::Backfilling, self::Scanning, self::NeedsReauth, self::Error],
        };
    }
}
