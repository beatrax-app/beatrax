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

    // The idle-lock window is carried to the browser in milliseconds, and the
    // minute-to-millisecond step was written out three times in three
    // spellings — `* 60_000`, `* 60 * 1000`, `* 60_000` — none of which
    // reached this enum. The conversion belongs here with the other one.
    public function milliseconds(): int
    {
        return $this->seconds() * 1000;
    }
}
