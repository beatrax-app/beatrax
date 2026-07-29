<?php

declare(strict_types=1);

namespace Tests\Helpers;

/**
 * A stream wrapper that serves real bytes and then refuses.
 *
 * Some failure branches only open when a read or a write fails partway
 * through a file that is otherwise well-formed — a disk giving up mid-copy,
 * a full filesystem. PHP will not produce those on demand from a real file,
 * so the path is handed to a wrapper instead: `beatraxfail://anything` reaches
 * this class, which replays `$data` until `$failAfter` bytes have gone by and
 * then returns false from fread, or returns 0 from fwrite when `$failWrites`
 * is set.
 *
 * Deliberately configured through statics rather than a stream context.
 * fopen() is called deep inside the code under test, which has no reason to
 * offer a seam for one, and the alternative is a constructor argument the
 * wrapper protocol does not have.
 */
final class FailingStream
{
    /** Bytes served to readers before the failure point. */
    public static string $data = '';

    /**
     * The 1-based read on which fread reports failure. A byte offset would be
     * the obvious knob and is the wrong one: PHP buffers userland stream reads
     * in 8 KiB chunks, so an offset lands mid-buffer and surfaces as a short
     * read — which the AEAD rejects as corruption, a different branch
     * entirely. Counting reads puts the false at the start of one.
     */
    public static int $failOnRead = PHP_INT_MAX;

    /**
     * Caps each read to this many bytes. Needed to empty PHP's buffer at a
     * chosen point: with the default 8 KiB fill, a reader that has consumed
     * only a header still has thousands of buffered bytes left, so the next
     * fread is served from the buffer and never reaches the wrapper.
     */
    public static int $chunkSize = PHP_INT_MAX;

    /** When true, every fwrite reports zero bytes written. */
    public static bool $failWrites = false;

    /** Required by the wrapper protocol; PHP assigns the stream context here. */
    public mixed $context = null;

    private int $position = 0;

    private int $reads = 0;

    public static function register(): void
    {
        if (in_array('beatraxfail', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('beatraxfail');
        }

        stream_wrapper_register('beatraxfail', self::class);
    }

    public static function reset(): void
    {
        self::$data = '';
        self::$failOnRead = PHP_INT_MAX;
        self::$chunkSize = PHP_INT_MAX;
        self::$failWrites = false;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;
        $this->reads = 0;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $this->reads++;
        if ($this->reads >= self::$failOnRead) {
            return false;
        }

        $chunk = substr(self::$data, $this->position, min($count, self::$chunkSize));
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_write(string $data): int
    {
        if (self::$failWrites) {
            return 0;
        }

        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$data);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::$data)];
    }

    public function stream_close(): void {}
}
