<?php

declare(strict_types=1);

use Modules\Core\Public\Exceptions\BoundedReadException;
use Modules\Core\Public\Support\BoundedRead;
use Psr\Http\Message\StreamInterface;

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

// getSize() is the declaration; read() is the cost of not believing it. A
// refusal that never calls read() is the whole point of checking the header.
function boundedReadRefusingStream(?int $declaredSize): StreamInterface
{
    return new class($declaredSize) implements StreamInterface
    {
        public function __construct(private ?int $declaredSize) {}

        public function __toString(): string
        {
            return $this->read(PHP_INT_MAX);
        }

        public function close(): void {}

        public function detach()
        {
            return null;
        }

        public function getSize(): ?int
        {
            return $this->declaredSize;
        }

        public function tell(): int
        {
            return 0;
        }

        public function eof(): bool
        {
            return false;
        }

        public function isSeekable(): bool
        {
            return false;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void {}

        public function rewind(): void {}

        public function isWritable(): bool
        {
            return false;
        }

        public function write(string $string): int
        {
            return 0;
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function read(int $length): string
        {
            throw new RuntimeException('the body must never be read');
        }

        public function getContents(): string
        {
            return $this->read(PHP_INT_MAX);
        }

        public function getMetadata(?string $key = null)
        {
            return null;
        }
    };
}

// A body that declares nothing and serves more than the ceiling allows, so
// the running total is the only thing that can stop it.
function boundedReadUndeclaredStream(int $totalBytes): StreamInterface
{
    return new class($totalBytes) implements StreamInterface
    {
        private int $served = 0;

        public function __construct(private int $totalBytes) {}

        public function __toString(): string
        {
            return '';
        }

        public function close(): void {}

        public function detach()
        {
            return null;
        }

        public function getSize(): ?int
        {
            return null;
        }

        public function tell(): int
        {
            return 0;
        }

        public function eof(): bool
        {
            return false;
        }

        public function isSeekable(): bool
        {
            return false;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void {}

        public function rewind(): void {}

        public function isWritable(): bool
        {
            return false;
        }

        public function write(string $string): int
        {
            return 0;
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function read(int $length): string
        {
            $chunk = str_repeat('A', min($length, $this->totalBytes - $this->served));
            $this->served += strlen($chunk);

            return $chunk;
        }

        public function getContents(): string
        {
            return $this->read($this->totalBytes);
        }

        public function getMetadata(?string $key = null)
        {
            return null;
        }
    };
}

it('refuses a source whose size cannot be determined, without reading a byte of it', function (): void {
    BoundedReadProbeStream::reset(str_repeat('A', 4096), statFails: true, reportedSize: 0);

    expect(fn (): string => BoundedRead::file('probe', 'boundedreadprobe://message.eml', 1024))
        ->toThrow(BoundedReadException::class, 'its size could not be determined');

    expect(BoundedReadProbeStream::$reads)->toBe(0);
});

it('reads a source whose stat succeeds and whose size is inside the ceiling', function (): void {
    BoundedReadProbeStream::reset('hello', statFails: false, reportedSize: 5);

    expect(BoundedRead::file('probe', 'boundedreadprobe://message.eml', 1024))->toBe('hello');
});

it('refuses a stated size past the ceiling before the bytes exist', function (): void {
    BoundedReadProbeStream::reset(str_repeat('A', 4096), statFails: false, reportedSize: 4096);

    expect(fn (): string => BoundedRead::file('probe', 'boundedreadprobe://message.eml', 1024))
        ->toThrow(BoundedReadException::class, '4096 bytes is past the 1024-byte ceiling');

    expect(BoundedReadProbeStream::$reads)->toBe(0);
});

// The window between the stat and the read belongs to whoever can write the
// folder, and on the drop-folder path that is the sender of the mail.
it('refuses a file that grew past the ceiling after it was measured', function (): void {
    BoundedReadProbeStream::reset(str_repeat('A', 4096), statFails: false, reportedSize: 10);

    expect(fn (): string => BoundedRead::file('probe', 'boundedreadprobe://message.eml', 1024))
        ->toThrow(BoundedReadException::class, 'is past the 1024-byte ceiling');
});

it('refuses a response that declares a length past the ceiling without reading the body', function (): void {
    expect(fn (): string => BoundedRead::stream('probe', boundedReadRefusingStream(26 * 1024 * 1024), 1024))
        ->toThrow(BoundedReadException::class, 'is past the 1024-byte ceiling');
});

it('refuses a response that declares no length once its bytes pass the ceiling', function (): void {
    expect(fn (): string => BoundedRead::stream('probe', boundedReadUndeclaredStream(4096), 1024))
        ->toThrow(BoundedReadException::class, 'is past the 1024-byte ceiling');
});

// A head refuses nothing: the caller asked for the front of the body, and a
// provider that answers a failure with megabytes must cost the front of it.
it('takes only the front of a body and leaves the rest unread', function (): void {
    $stream = boundedReadUndeclaredStream(4096);

    expect(strlen(BoundedRead::head($stream, 1024)))->toBe(1024);
});
