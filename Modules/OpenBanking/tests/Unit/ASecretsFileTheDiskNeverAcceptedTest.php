<?php

declare(strict_types=1);

use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsFile;
use Modules\OpenBanking\Internal\Services\SecretsWriteFailed;
use Modules\OpenBanking\Tests\Support\OpenBankingReversingShield;
use Tests\Helpers\FailingStream;

/** @link ../../../../.docs/features/open-banking/secrets-at-rest.md#the-atomic-write */

// The write is atomic so a reader never sees half a secrets file, and the
// question every arm below asks is the same one: are the bytes on the disk?
// A full filesystem cannot be staged with a real file — fwrite answers from a
// userspace buffer, so the count agrees and the failure arrives at the flush.
// Over a registered scheme each arm is reachable on its own.

function obUnflushableSecretsFile(): OpenBankingSecretsFile
{
    return new OpenBankingSecretsFile(
        new Filesystem,
        new OpenBankingReversingShield,
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
    );
}

afterEach(function (): void {
    FailingStream::reset();
});

it('refuses a secrets file the filesystem only half accepted', function (): void {
    FailingStream::register();
    FailingStream::$failWrites = true;
    $path = 'beatraxfail://blobs/2026/06/message.eml';

    expect(fn () => obUnflushableSecretsFile()->write($path, ['client_id' => 'abc']))
        ->toThrow(SecretsWriteFailed::class, 'short write');
});

it('refuses a secrets file whose bytes the flush could not put on disk', function (): void {
    FailingStream::register();
    FailingStream::$failFlush = true;
    $path = 'beatraxfail://blobs/2026/06/message.eml';

    expect(fn () => obUnflushableSecretsFile()->write($path, ['client_id' => 'abc']))
        ->toThrow(SecretsWriteFailed::class, 'fflush failed');
});

it('refuses a secrets file the flush accepted and the fsync did not', function (): void {
    FailingStream::register();
    $path = 'beatraxfail://blobs/2026/06/message.eml';

    expect(fn () => obUnflushableSecretsFile()->write($path, ['client_id' => 'abc']))
        ->toThrow(SecretsWriteFailed::class, 'fsync failed');
});
