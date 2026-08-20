<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Throwable;

final class SafeTrace
{
    // Deep recursions or framework boot frames otherwise dominate the log
    // entry without adding diagnostic value past the first dozen frames.
    private const DEFAULT_MAX_LINES = 20;

    // Rewrites every absolute path starting with $basePath to a path relative
    // to the project root, then truncates to $maxLines frames, appending a
    // trailing "... +N more" marker when the original trace exceeded the cap.
    public static function cap(Throwable $throwable, string $basePath, int $maxLines = self::DEFAULT_MAX_LINES): string
    {
        $trace = $throwable->getTraceAsString();
        $rooted = rtrim($basePath, '/').'/';
        $stripped = str_replace($rooted, '', $trace);

        $lines = explode("\n", $stripped);
        if (count($lines) <= $maxLines) {
            return implode("\n", $lines);
        }

        $kept = array_slice($lines, 0, $maxLines);
        $remaining = count($lines) - $maxLines;
        $kept[] = sprintf('… +%d more', $remaining);

        return implode("\n", $kept);
    }
}
