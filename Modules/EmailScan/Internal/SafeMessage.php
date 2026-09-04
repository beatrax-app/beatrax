<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use Modules\Core\Public\Support\PatternScan;

// 300 bytes is long enough for the provider's actual hint and short enough to
// fit a session flash payload.
final class SafeMessage
{
    public static function cap(string $raw, int $max = 300): string
    {
        $oneLine = PatternScan::replace('/\s+/', ' ', $raw);

        return substr($oneLine, 0, $max);
    }
}
