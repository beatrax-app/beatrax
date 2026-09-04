<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Modules\Core\Public\Exceptions\BoundedReadException;
use Psr\Http\Message\StreamInterface;

// A whole read whose length the sender chooses is a fatal waiting to be sent
// one: this backend runs inside a phone's 128 MB ceiling, where an exhausted
// heap is E_ERROR — no exception, no log, no retry. Every such read settles
// its size here first, and a size nobody can state is refused, not trusted.
final class BoundedRead
{
    // Big enough that a 25 MB body is fifty reads, small enough that the
    // overshoot past the ceiling before the refusal is half a megabyte.
    private const int STREAM_CHUNK_BYTES = 1 << 19;

    public static function refuseAbove(string $subject, int $declaredBytes, int $maxBytes): void
    {
        if ($declaredBytes > $maxBytes) {
            throw BoundedReadException::tooLarge($subject, $declaredBytes, $maxBytes);
        }
    }

    // A stat that fails refuses the file: skipping the check instead read
    // whatever was there whole, in the one case where the size was least
    // knowable. The second check closes the window in which the file grows
    // between being measured and being read.
    /**
     * @param  positive-int  $maxBytes
     */
    public static function file(string $subject, string $path, int $maxBytes): string
    {
        $declaredBytes = @filesize($path);
        if ($declaredBytes === false) {
            throw BoundedReadException::unmeasurable($subject);
        }

        self::refuseAbove($subject, $declaredBytes, $maxBytes);

        $contents = @file_get_contents($path, length: $maxBytes + 1);
        if ($contents === false) {
            throw BoundedReadException::unreadable($subject);
        }

        self::refuseAbove($subject, strlen($contents), $maxBytes);

        return $contents;
    }

    // Some readers only want the front of a body — an error message, a header
    // to sniff. Refusing an over-long one would lose a diagnosis worth having,
    // so the head declines to hold the rest instead of rejecting the whole.
    /**
     * @param  positive-int  $maxBytes
     */
    public static function head(StreamInterface $stream, int $maxBytes): string
    {
        $read = '';
        while (! $stream->eof() && strlen($read) < $maxBytes) {
            $chunk = $stream->read(min(self::STREAM_CHUNK_BYTES, $maxBytes - strlen($read)));
            if ($chunk === '') {
                break;
            }

            $read .= $chunk;
        }

        return $read;
    }

    // Content-Length settles it without reading a byte. Without one the body
    // is taken a chunk at a time and abandoned the moment it passes the
    // ceiling, so a sender who declares nothing costs one chunk of overshoot
    // rather than the process.
    public static function stream(string $subject, StreamInterface $stream, int $maxBytes): string
    {
        $declaredBytes = $stream->getSize();
        if ($declaredBytes !== null) {
            self::refuseAbove($subject, $declaredBytes, $maxBytes);
        }

        $read = '';
        while (! $stream->eof()) {
            $chunk = $stream->read(self::STREAM_CHUNK_BYTES);
            if ($chunk === '') {
                break;
            }

            $read .= $chunk;
            self::refuseAbove($subject, strlen($read), $maxBytes);
        }

        return $read;
    }
}
