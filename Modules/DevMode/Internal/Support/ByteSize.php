<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Support;

use Modules\Core\Public\Support\Fmt;

// One rendering of a byte count for the whole Dev Console, so a size read off
// the log tab and a size read off the system tab mean the same thing. The log
// tailer's Alpine mirror in log-tailer-page.blade.php follows this.
final class ByteSize
{
    private const int PER_UNIT = 1024;

    public static function human(int $bytes): string
    {
        $kb = $bytes / self::PER_UNIT;
        $mb = $kb / self::PER_UNIT;

        return match (true) {
            $bytes < self::PER_UNIT => Fmt::number($bytes).' B',
            $kb < self::PER_UNIT => Fmt::number($kb, 1).' KB',
            $mb < self::PER_UNIT => Fmt::number($mb, 1).' MB',
            default => Fmt::number($mb / self::PER_UNIT, 2).' GB',
        };
    }
}
