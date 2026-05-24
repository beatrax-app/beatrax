<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

/**
 * Single-shot tail primitive for a growing file.
 *
 * Pure-PHP utility shared between the artisan-run SSE controller (this
 * plan) and the log-tailer SSE controller (16-05). Both surfaces need
 * the same "open file, seek to known offset, read new bytes, return
 * chunk + new offset" pattern; centralising it here keeps the two SSE
 * pipelines on one tested seam.
 *
 * `tailOnce()` invariants:
 *   - `clearstatcache()` is called BEFORE `filesize()` so a growing
 *     file's new size is observed (PHP caches filesize per request
 *     by default).
 *   - If the file does not exist OR `filesize() < $fromOffset`
 *     (rotation / truncation), returns an empty chunk and the
 *     UNCHANGED `$fromOffset`. The caller decides whether to reset
 *     to 0 or keep waiting.
 *   - Reads at most 65 536 bytes per call. The SSE loop calls this
 *     once per tick (150 ms in ArtisanStreamController, 250 ms in
 *     LogStreamController), giving ~430 KB/s and ~262 KB/s throughput
 *     ceilings respectively — well above any whitelisted SAFE
 *     command's stdout rate.
 *
 * No constructor — stateless pure function class. No DI; no facades.
 */
final readonly class FileTailer
{
    /** Maximum bytes read per tail tick. */
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
