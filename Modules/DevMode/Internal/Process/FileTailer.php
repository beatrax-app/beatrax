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
        $length = $this->readableLength($path, $fromOffset);
        $chunk = $length < 1 ? '' : $this->readAt($path, $fromOffset, $length);

        // "Nothing new — keep your offset" was written out seven times here.
        // Every reason for it (missing, rotated, truncated, caught up,
        // unopenable, unseekable, empty read) is one answer, and the unchanged
        // offset is what preserves the caller's idempotency.
        return $chunk === ''
            ? ['chunk' => '', 'newOffset' => $fromOffset]
            : ['chunk' => $chunk, 'newOffset' => $fromOffset + strlen($chunk)];
    }

    // How many bytes are available from $fromOffset, capped at one chunk; 0
    // when there is nothing to read. A size below the caller's offset is
    // rotation or truncation and a size equal to it means caught up — both
    // are "nothing", which is why they share the one comparison.
    private function readableLength(string $path, int $fromOffset): int
    {
        clearstatcache(true, $path);

        if (! is_file($path)) {
            return 0;
        }

        $size = filesize($path);
        if ($size === false || $size <= $fromOffset) {
            return 0;
        }

        return min($size - $fromOffset, self::READ_CHUNK_BYTES);
    }

    // The bytes at $fromOffset, or '' when the file cannot be opened, will not
    // seek to that offset, or yields nothing.
    private function readAt(string $path, int $fromOffset, int $length): string
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return '';
        }

        try {
            if (@fseek($handle, $fromOffset) !== 0) {
                return '';
            }

            $chunk = @fread($handle, max(1, $length));

            return $chunk === false ? '' : $chunk;
        } finally {
            @fclose($handle);
        }
    }
}
