<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use Modules\Core\Public\Services\UserDataPathService;
use SplFileObject;
use Throwable;

/**
 * Aggregates counts + sizes from the daily Laravel log files so the
 * Log Tailer page can show per-severity tallies, total lines, today's
 * file size, and the cumulative size of every retained daily file.
 *
 * Today's file is parsed line-by-line via SplFileObject (no full-file
 * load) so a multi-megabyte log does not blow the request budget. The
 * cumulative all-files size is a `filesize()` sum over the
 * `laravel-*.log` glob in the logs directory.
 *
 * The parser matches the standard Monolog daily format:
 *
 *   [YYYY-MM-DD HH:MM:SS] channel.SEVERITY: message body
 *
 * Continuation lines (stack-trace rows, JSON tails) increment the
 * total-line count but not any per-severity bucket — same logic the
 * client-side tailer uses to fold them into the previous entry.
 */
final readonly class LogFileStats
{
    /** Cap the parse so a runaway log file cannot stall a request. */
    private const int MAX_LINES = 100_000;

    /**
     * @return array{
     *   path: string,
     *   exists: bool,
     *   sizeBytes: int,
     *   totalLines: int,
     *   parsedLines: int,
     *   perSeverity: array<string, int>,
     *   capped: bool,
     * }
     */
    public function forToday(): array
    {
        $path = UserDataPathService::dailyLogFile();
        $perSeverity = [
            'DEBUG' => 0,
            'INFO' => 0,
            'NOTICE' => 0,
            'WARNING' => 0,
            'ERROR' => 0,
            'CRITICAL' => 0,
            'ALERT' => 0,
            'EMERGENCY' => 0,
        ];

        if (! is_file($path) || ! is_readable($path)) {
            return [
                'path' => $path,
                'exists' => false,
                'sizeBytes' => 0,
                'totalLines' => 0,
                'parsedLines' => 0,
                'perSeverity' => $perSeverity,
                'capped' => false,
            ];
        }

        $sizeBytes = @filesize($path);
        if ($sizeBytes === false) {
            $sizeBytes = 0;
        }

        $totalLines = 0;
        $parsedLines = 0;
        $capped = false;

        try {
            $file = new SplFileObject($path, 'r');
            $file->setFlags(SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);

            foreach ($file as $line) {
                if (! is_string($line) || $line === '') {
                    continue;
                }
                $totalLines++;
                if ($totalLines > self::MAX_LINES) {
                    $capped = true;
                    break;
                }
                $severity = self::extractSeverity($line);
                if ($severity === null) {
                    continue;
                }
                $parsedLines++;
                if (isset($perSeverity[$severity])) {
                    $perSeverity[$severity]++;
                }
            }
        } catch (Throwable) {
            // Unreadable or mid-rotation — fall through with whatever
            // we counted so far; the dashboard prefers a partial view
            // over a hard error on a transient FS state.
        }

        return [
            'path' => $path,
            'exists' => true,
            'sizeBytes' => $sizeBytes,
            'totalLines' => $totalLines,
            'parsedLines' => $parsedLines,
            'perSeverity' => $perSeverity,
            'capped' => $capped,
        ];
    }

    /**
     * Sum the file sizes of every retained `laravel-*.log` file in
     * the daily-rotation directory. The directory enumeration uses
     * glob() to avoid pulling sibling non-log files (the dir hosts
     * channel-specific sub-paths too in some deployments).
     *
     * @return array{count: int, totalBytes: int}
     */
    public function allFiles(): array
    {
        $dir = UserDataPathService::logsDirectory();
        $matches = @glob($dir.DIRECTORY_SEPARATOR.'laravel-*.log');
        if (! is_array($matches)) {
            return ['count' => 0, 'totalBytes' => 0];
        }

        $total = 0;
        $count = 0;
        foreach ($matches as $file) {
            if (! is_file($file)) {
                continue;
            }
            $size = @filesize($file);
            if ($size === false) {
                continue;
            }
            $total += $size;
            $count++;
        }

        return ['count' => $count, 'totalBytes' => $total];
    }

    /**
     * Truncate today's file to zero bytes. Preserves the inode so the
     * Log Tailer's polling endpoint detects the size shrink via the
     * `?since` past-current-size branch and signals reset to the
     * client. Returns the bytes freed (0 when the file is missing).
     */
    public function truncateToday(): int
    {
        $path = UserDataPathService::dailyLogFile();
        if (! is_file($path) || ! is_writable($path)) {
            return 0;
        }
        $sizeBytes = @filesize($path);
        $sizeBytes = $sizeBytes === false ? 0 : $sizeBytes;

        $handle = @fopen($path, 'r+');
        if ($handle === false) {
            return 0;
        }
        try {
            ftruncate($handle, 0);
            fflush($handle);
        } finally {
            fclose($handle);
        }

        clearstatcache(true, $path);

        return $sizeBytes;
    }

    /**
     * Extract the SEVERITY token from a single Laravel log line, or
     * null when the line does not match the header shape (continuation
     * line, stack-trace row, JSON payload tail).
     */
    private static function extractSeverity(string $line): ?string
    {
        if (preg_match('/^\[[^\]]+\]\s+[a-z0-9_]+\.([A-Z]+):/i', $line, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }
}
