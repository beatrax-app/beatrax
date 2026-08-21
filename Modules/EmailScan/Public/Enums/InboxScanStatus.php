<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Enums;

// The column, its DTOs and applyStatus()'s signature all stay string; this
// enum is the canonical spelling callers map through, and it owns the
// transition graph InboxScanStateMachine's guard enforces.
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
