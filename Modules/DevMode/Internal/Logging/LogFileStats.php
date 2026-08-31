<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use SplFileObject;
use Throwable;

// Line-by-line via SplFileObject rather than file_get_contents: a
// multi-megabyte log would otherwise blow the request's memory budget.
final readonly class LogFileStats
{
    private const int MAX_LINES = 100_000;

    public function __construct(private ActiveLogFile $file) {}

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
    public function current(): array
    {
        $path = $this->file->path();
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
                // Tested before the increment: counting the line that trips
                // the cap reported one line more than the cap declares, and
                // left totalLines disagreeing by one with parsedLines and the
                // severity breakdown rendered beside it.
                if ($totalLines >= self::MAX_LINES) {
                    $capped = true;
                    break;
                }
                $totalLines++;
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
            // Mid-rotation is a transient FS state, so the counts so far beat
            // a hard error on the dashboard.
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

    // The glob is narrowed to the active channel's own filenames because some
    // deployments put channel-specific sub-paths in the same directory.
    /**
     * @return array{count: int, totalBytes: int}
     */
    public function allFiles(): array
    {
        $matches = @glob($this->file->siblingGlob());
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

    // ftruncate rather than unlink: keeping the inode is what lets the polling
    // endpoint see the size shrink and signal a reset to the tailer.
    public function truncate(): int
    {
        $path = $this->file->path();
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

    // Null for stack-trace rows and JSON payload tails: they are continuations
    // of the entry above, not entries of their own.
    private static function extractSeverity(string $line): ?string
    {
        if (preg_match('/^\[[^\]]+\]\s+[a-z0-9_]+\.([A-Z]+):/i', $line, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }
}
