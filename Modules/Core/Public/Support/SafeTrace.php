<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Throwable;

final class SafeTrace
{
    // Deep recursions or framework boot frames otherwise dominate the log
    // entry without adding diagnostic value past the first dozen frames.
    private const int DEFAULT_MAX_LINES = 20;

    // Assembled frame by frame rather than from getTraceAsString(), which
    // renders the first 15 characters of every string argument while
    // zend.exception_ignore_args is Off — and on the parse frames these catch
    // blocks log, that argument is a row of the reader's bank statement.
    public static function cap(Throwable $throwable, string $basePath, int $maxLines = self::DEFAULT_MAX_LINES): string
    {
        $frames = [];

        foreach ($throwable->getTrace() as $depth => $frame) {
            $frames[] = sprintf('#%d %s: %s', $depth, self::origin($frame), self::callee($frame));
        }

        $frames[] = sprintf('#%d {main}', count($frames));

        $rooted = rtrim($basePath, '/').'/';
        $stripped = str_replace($rooted, '', implode("\n", $frames));

        $lines = explode("\n", $stripped);
        if (count($lines) <= $maxLines) {
            return implode("\n", $lines);
        }

        $kept = array_slice($lines, 0, $maxLines);
        $remaining = count($lines) - $maxLines;
        $kept[] = sprintf('… +%d more', $remaining);

        return implode("\n", $kept);
    }

    /**
     * @param  array{function: string, line?: int, file?: string, class?: class-string, type?: '->'|'::', args?: list<mixed>, object?: object}  $frame
     */
    private static function origin(array $frame): string
    {
        return isset($frame['file'], $frame['line'])
            ? sprintf('%s(%d)', $frame['file'], $frame['line'])
            : '[internal function]';
    }

    /**
     * @param  array{function: string, line?: int, file?: string, class?: class-string, type?: '->'|'::', args?: list<mixed>, object?: object}  $frame
     */
    private static function callee(array $frame): string
    {
        return sprintf('%s%s%s()', $frame['class'] ?? '', $frame['type'] ?? '', $frame['function']);
    }
}
