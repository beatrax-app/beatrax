<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

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

        // Every failure mode — missing, rotated, truncated, caught up,
        // unreadable — returns the caller's offset unchanged, which is what
        // makes a re-poll idempotent.
        return $chunk === ''
            ? ['chunk' => '', 'newOffset' => $fromOffset]
            : ['chunk' => $chunk, 'newOffset' => $fromOffset + strlen($chunk)];
    }

    // A size below the caller's offset is rotation or truncation, equal to it
    // is caught up; both are "nothing", hence the single `<=`.
    private function readableLength(string $path, int $fromOffset): int
    {
        // PHP caches stat results per path, so filesize() below would answer
        // from the previous poll and a still-growing file would never advance.
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
