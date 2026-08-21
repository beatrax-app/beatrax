<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// Names the whole-unit durations that otherwise recur as bare second counts
// (3600, 86400, 60) in job intervals, TTLs and relative-time math. The
// human-readable case is the spelling; seconds() is the machine value every
// caller derives from, so no site re-computes the conversion by hand.
enum Duration: string
{
    case Minute = 'minute';

    case Hour = 'hour';

    case Day = 'day';

    public function seconds(): int
    {
        return match ($this) {
            self::Minute => 60,
            self::Hour => 3600,
            self::Day => 86400,
        };
    }
}
