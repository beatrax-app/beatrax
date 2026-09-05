<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support;

// A source whose stat fails but whose bytes still flow. filesize() answering
// false is the case the old guard skipped, and skipping it read the whole
// thing anyway — so the reads are counted here and the count is the assertion.
final class BoundedReadProbeStream
{
    public static string $data = '';

    public static bool $statFails = false;

    public static int $reportedSize = 0;

    public static int $reads = 0;

    public mixed $context = null;

    private int $position = 0;

    public static function reset(string $data, bool $statFails, int $reportedSize): void
    {
        self::$data = $data;
        self::$statFails = $statFails;
        self::$reportedSize = $reportedSize;
        self::$reads = 0;

        if (! in_array('boundedreadprobe', stream_get_wrappers(), true)) {
            stream_wrapper_register('boundedreadprobe', self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        self::$reads++;
        $chunk = substr(self::$data, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$data);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return false;
    }

    public function stream_stat(): array|false
    {
        return $this->url_stat('', 0);
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return self::$statFails ? false : ['mode' => 0100644, 'size' => self::$reportedSize];
    }
}
