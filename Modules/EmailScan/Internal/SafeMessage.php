<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

// 300 bytes is long enough for the provider's actual hint and short enough to
// fit a session flash payload.
final class SafeMessage
{
    public static function cap(string $raw, int $max = 300): string
    {
        $oneLine = (string) preg_replace('/\s+/', ' ', $raw);

        return substr($oneLine, 0, $max);
    }
}
