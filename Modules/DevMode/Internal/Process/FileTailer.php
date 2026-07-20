<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

// Shared tail primitive between the artisan-run and log-tailer SSE
// controllers. clearstatcache() runs BEFORE filesize() so growth is
// observed; a missing file or filesize() < $fromOffset (rotation)
// returns an empty chunk + the UNCHANGED offset for the caller to decide.
final readonly class FileTailer
{
    private const READ_CHUNK_BYTES = 65_536;

    /**
     * @return array{chunk: string, newOffset: int}
     */
    public function tailOnce(string $path, int $fromOffset): array
    {
        clearstatcache(true, $path);

        if (! is_file($path)) {
            return ['chunk' => '', 'newOffset' => $fromOffset];
        }

        $size = filesize($path);
        if ($size === false || $size < $fromOffset) {
            // Rotation / truncation: keep the caller's offset so they
            // can decide whether to reset to 0 or wait. Returning the
            // unchanged offset preserves caller idempotency.
            return ['chunk' => '', 'newOffset' => $fromOffset];
        }

        if ($size === $fromOffset) {
            return ['chunk' => '', 'newOffset' => $fromOffset];
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return ['chunk' => '', 'newOffset' => $fromOffset];
        }

        try {
            if (@fseek($handle, $fromOffset) !== 0) {
                return ['chunk' => '', 'newOffset' => $fromOffset];
            }

            $remaining = $size - $fromOffset;
            // $remaining is guaranteed >= 1 here: the earlier `$size === $fromOffset`
            // branch returned, and PHP's fread() requires the length to be a positive
            // int (PHPStan: int<1, max>).
            $toRead = $remaining > self::READ_CHUNK_BYTES ? self::READ_CHUNK_BYTES : $remaining;
            if ($toRead < 1) {
                return ['chunk' => '', 'newOffset' => $fromOffset];
            }

            $chunk = @fread($handle, $toRead);
            if ($chunk === false || $chunk === '') {
                return ['chunk' => '', 'newOffset' => $fromOffset];
            }

            return [
                'chunk' => $chunk,
                'newOffset' => $fromOffset + strlen($chunk),
            ];
        } finally {
            @fclose($handle);
        }
    }
}
