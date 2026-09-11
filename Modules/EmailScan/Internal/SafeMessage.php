<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use Modules\Core\Public\Support\PatternScan;

// 300 bytes is long enough for the provider's actual hint and short enough to
// fit a session flash payload. mb_strcut, not substr: the hint is the
// provider's own sentence in the provider's own language, and a plain cut at
// byte 300 lands inside a character and renders the end of it as a lozenge.
final class SafeMessage
{
    public static function cap(string $raw, int $max = 300): string
    {
        $oneLine = PatternScan::replace('/\s+/', ' ', $raw);

        return mb_strcut($oneLine, 0, $max);
    }
}
