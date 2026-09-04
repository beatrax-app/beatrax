<?php

declare(strict_types=1);

use Modules\Core\Public\Support\OwnerOnlyPath;
use Psr\Log\AbstractLogger;

// chmod() answering true is not the same as the mode landing. /dev/null is the
// one path on a POSIX machine that takes a write from an ordinary user and
// refuses the chmod, which is the shape a return-value check cannot see: the
// write succeeds, the caller reports success, and the bytes stay at 0666.
const OWNER_ONLY_UNCHMODABLE = '/dev/null';

function ownerOnlyLogger(): object
{
    return new class extends AbstractLogger
    {
        /** @var list<array{message: string, context: array<mixed>}> */
        public array $records = [];

        /**
         * @param  mixed  $level
         * @param  Stringable|string  $message
         * @param  array<mixed>  $context
         */
        public function log($level, $message, array $context = []): void
        {
            $this->records[] = ['message' => (string) $message, 'context' => $context];
        }
    };
}

function ownerOnlyScratchDirectory(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-owner-only-'.bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);

    return $dir;
}

function ownerOnlyObservedMode(string $path): int
{
    clearstatcache(true, $path);

    return (int) fileperms($path) & 0777;
}

beforeEach(function (): void {
    $this->scratch = ownerOnlyScratchDirectory();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg((string) $this->scratch));
});

it('creates a file owner-only under the default umask that would have made it 0644', function (): void {
    $previous = umask(0o022);
    $path = $this->scratch.'/secret.bin';

    try {
        expect((new OwnerOnlyPath)->file($path))->toBeTrue()
            ->and(ownerOnlyObservedMode($path))->toBe(0o600);
    } finally {
        umask($previous);
    }
});

it('narrows a file that already exists wider', function (): void {
    $path = $this->scratch.'/restored-by-a-copy.sqlite';
    touch($path);
    chmod($path, 0o644);

    expect((new OwnerOnlyPath)->file($path))->toBeTrue()
        ->and(ownerOnlyObservedMode($path))->toBe(0o600);
});

it('narrows a directory that already exists wider', function (): void {
    expect(ownerOnlyObservedMode((string) $this->scratch))->toBe(0o755)
        ->and((new OwnerOnlyPath)->directory((string) $this->scratch))->toBeTrue()
        ->and(ownerOnlyObservedMode((string) $this->scratch))->toBe(0o700);
});

it('creates a missing directory owner-only', function (): void {
    $path = $this->scratch.'/one/two/three';

    expect((new OwnerOnlyPath)->directory($path))->toBeTrue()
        ->and(ownerOnlyObservedMode($path))->toBe(0o700);
});

// The whole point of the class: the answer is the mode on disk afterwards, so
// a path the process may write but may not chmod is a refusal rather than a
// success reported over bytes anyone can read.
it('refuses a path whose mode will not settle, and says how to fix it', function (): void {
    $logger = ownerOnlyLogger();

    expect((new OwnerOnlyPath($logger))->file(OWNER_ONLY_UNCHMODABLE))->toBeFalse()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context']['observed_mode'])->toBe('0666')
        ->and($logger->records[0]['context']['expected_mode'])->toBe('0600')
        ->and($logger->records[0]['context']['remedy'])->toBe('chmod 600 '.OWNER_ONLY_UNCHMODABLE);
});

it('refuses a directory a file already occupies', function (): void {
    $path = $this->scratch.'/not-a-directory';
    file_put_contents($path, 'in the way');
    $logger = ownerOnlyLogger();

    expect((new OwnerOnlyPath($logger))->directory($path))->toBeFalse()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context']['path'])->toBe($path);
});

it('stays silent with no logger bound', function (): void {
    expect(fn (): bool => (new OwnerOnlyPath)->file(OWNER_ONLY_UNCHMODABLE))->not->toThrow(Throwable::class);
});
