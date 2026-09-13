<?php

declare(strict_types=1);

namespace Tests\Helpers;

// A `beatraxfail://` path serves real bytes and then refuses, so tests can
// reach failure branches PHP will not produce from a real file. Configured
// through statics because fopen() is called deep inside the code under test
// and the wrapper protocol has no constructor to pass a context to.
final class FailingStream
{
    public static string $data = '';

    // Counts reads, not bytes: PHP buffers userland stream reads in 8 KiB
    // chunks, so a byte offset lands mid-buffer and surfaces as a short read,
    // which the AEAD rejects as corruption — a different branch entirely.
    public static int $failOnRead = PHP_INT_MAX;

    // Empties PHP's read buffer at a chosen point. With the default 8 KiB
    // fill, a reader that has consumed only a header still has thousands of
    // buffered bytes left and its next fread never reaches the wrapper.
    public static int $chunkSize = PHP_INT_MAX;

    // Zero bytes, not a partial count: PHP loops on a userland write and
    // re-offers whatever the wrapper declined, so a short answer is retried
    // until it adds up and fwrite reports the full length. Only a write that
    // accepts nothing breaks that loop.
    public static bool $failWrites = false;

    // A full disk surfaces here, not at the write: fwrite copies into a
    // userspace buffer and reports success, so the failure arrives when that
    // buffer is drained. fsync needs no flag — PHP refuses to fsync anything
    // that is not a plain file, so it answers false over any wrapper.
    public static bool $failFlush = false;

    // Required by the wrapper protocol; PHP assigns the stream context here.
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
        self::$failFlush = false;
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

    public function stream_flush(): bool
    {
        return ! self::$failFlush;
    }

    public function stream_lock(int $operation): bool
    {
        return true;
    }

    // The three the atomic-write paths reach for around the bytes themselves.
    // Answering them lets a case reach the write it is about rather than
    // failing on the directory or the chmod before it.
    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return true;
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return true;
    }

    // Answers "cannot stat" rather than being left off. Every atomic-write
    // path asks whether the parent directory is there before it opens
    // anything, and an unimplemented url_stat makes is_dir() raise a warning
    // that Pest promotes to an exception well before the case gets started.
    public function url_stat(string $path, int $flags): array|false
    {
        return false;
    }

    public function rename(string $from, string $to): bool
    {
        return true;
    }

    public function stream_close(): void {}
}
