<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Throwable;

/**
 * Captures a Throwable's stack trace in a form safe for log surfaces
 * that can be screen-shared (e.g. the in-app `/dev/logs` viewer).
 *
 * Raw `Throwable::getTraceAsString()` embeds absolute filesystem paths
 * which leak the developer's home directory layout to anyone the
 * dev-logs viewer is shown to. `SafeTrace::cap()` rewrites every
 * absolute path inside the trace string to a path relative to the
 * application root and truncates the trace to a configurable line
 * count so a deep recursion does not flood the log.
 *
 * Per ASVS V7 (Errors & Logging) production-style logs should not
 * leak filesystem paths to a surface that can be screen-shared. This
 * helper centralises the rewrite so every call site shares one
 * sanitiser.
 */
final class SafeTrace
{
    /**
     * Truncate the trace at this many lines. Deep recursions or
     * framework boot frames otherwise dominate the log entry without
     * adding diagnostic value past the first dozen frames.
     */
    private const DEFAULT_MAX_LINES = 20;

    /**
     * Returns a sanitised stack trace for `$throwable`:
     *
     *   - Every absolute path starting with `$basePath` is rewritten
     *     to a path relative to the project root.
     *   - The trace is truncated to `$maxLines` (default 20) frames;
     *     a trailing `… +N more` marker is appended when the original
     *     trace exceeded the cap.
     */
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
