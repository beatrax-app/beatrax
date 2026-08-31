<?php

declare(strict_types=1);

use Modules\Core\Public\Exceptions\BackupDecryptionException;
use Modules\Core\Public\Exceptions\BackupFormatException;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Services\BackupEncryptor;
use Tests\Helpers\FailingStream;

/**
 * @return array{plain: string, enc: string, dec: string, cleanup: Closure}
 */
function backupTmpPaths(): array
{
    $base = sys_get_temp_dir().'/beatrax-bk-'.bin2hex(random_bytes(6));
    $paths = ['plain' => $base.'.sqlite', 'enc' => $base.'.enc', 'dec' => $base.'.dec'];

    return $paths + ['cleanup' => function () use ($paths): void {
        foreach (['plain', 'enc', 'dec'] as $k) {
            if (is_file($paths[$k])) {
                @unlink($paths[$k]);
            }
        }
    }];
}

it('round-trips a multi-chunk payload byte-for-byte', function (): void {
    $p = backupTmpPaths();
    // ~200 KiB of binary data spans several 64 KiB AEAD chunks.
    $payload = random_bytes(200_000);
    file_put_contents($p['plain'], $payload);

    $enc = new BackupEncryptor;
    $enc->encrypt($p['plain'], $p['enc'], 'correct horse battery staple');
    $enc->decrypt($p['enc'], $p['dec'], 'correct horse battery staple');

    expect(file_get_contents($p['dec']))->toBe($payload);
    expect(file_get_contents($p['enc']))->not->toContain($payload);

    $p['cleanup']();
});

it('round-trips an empty and a sub-chunk payload', function (): void {
    foreach (['', 'tiny'] as $payload) {
        $p = backupTmpPaths();
        file_put_contents($p['plain'], $payload);
        $enc = new BackupEncryptor;
        $enc->encrypt($p['plain'], $p['enc'], 'pw');
        $enc->decrypt($p['enc'], $p['dec'], 'pw');
        expect(file_get_contents($p['dec']))->toBe($payload);
        $p['cleanup']();
    }
});

it('fails to decrypt with the wrong passphrase', function (): void {
    $p = backupTmpPaths();
    file_put_contents($p['plain'], random_bytes(50_000));
    $enc = new BackupEncryptor;
    $enc->encrypt($p['plain'], $p['enc'], 'right-passphrase');

    expect(fn () => $enc->decrypt($p['enc'], $p['dec'], 'wrong-passphrase'))
        ->toThrow(BackupDecryptionException::class);

    $p['cleanup']();
});

it('detects a tampered byte in the ciphertext', function (): void {
    $p = backupTmpPaths();
    file_put_contents($p['plain'], random_bytes(50_000));
    $enc = new BackupEncryptor;
    $enc->encrypt($p['plain'], $p['enc'], 'pw');

    $bytes = (string) file_get_contents($p['enc']);
    $i = intdiv(strlen($bytes), 2);
    $bytes[$i] = $bytes[$i] === "\x00" ? "\x01" : "\x00";
    file_put_contents($p['enc'], $bytes);

    expect(fn () => $enc->decrypt($p['enc'], $p['dec'], 'pw'))
        ->toThrow(BackupDecryptionException::class);

    // A failed decrypt must not leave partial (valid-looking) plaintext behind.
    expect(is_file($p['dec']))->toBeFalse();

    $p['cleanup']();
});

it('rejects out-of-range Argon2id parameters in the header (pre-auth DoS guard)', function (): void {
    $p = backupTmpPaths();
    file_put_contents($p['plain'], random_bytes(10_000));
    $enc = new BackupEncryptor;
    $enc->encrypt($p['plain'], $p['enc'], 'pw');

    // Overwrite the memlimit field (8 bytes at offset 8+16+4 = 28) with a value
    // far above the SENSITIVE cap, so deriveKey must reject it BEFORE allocating.
    $bytes = (string) file_get_contents($p['enc']);
    $bytes = substr($bytes, 0, 28).pack('P', 1 << 40).substr($bytes, 36);
    file_put_contents($p['enc'], $bytes);

    expect(fn () => $enc->decrypt($p['enc'], $p['dec'], 'pw'))
        ->toThrow(BackupFormatException::class, 'outside the accepted range');
    expect(is_file($p['dec']))->toBeFalse();

    $p['cleanup']();
});

// The test above uses a value libsodium itself would refuse, which says
// nothing about the range in between. SENSITIVE is a parameter set libsodium
// will happily run — 12.3 s and a gigabyte of allocation it takes outside
// PHP's memory_limit — and nothing here has ever written one.
it('refuses header parameters libsodium would run but this application never writes', function (): void {
    $p = backupTmpPaths();
    file_put_contents($p['plain'], random_bytes(10_000));
    $enc = new BackupEncryptor;
    $enc->encrypt($p['plain'], $p['enc'], 'pw');

    $bytes = (string) file_get_contents($p['enc']);
    $sensitive = substr($bytes, 0, 24)
        .pack('V', SODIUM_CRYPTO_PWHASH_OPSLIMIT_SENSITIVE)
        .pack('P', SODIUM_CRYPTO_PWHASH_MEMLIMIT_SENSITIVE)
        .substr($bytes, 36);
    file_put_contents($p['enc'], $sensitive);

    $started = microtime(true);

    expect(fn () => $enc->decrypt($p['enc'], $p['dec'], 'pw'))
        ->toThrow(BackupFormatException::class, 'outside the accepted range');

    // Refused, not merely survived: deriving under those parameters takes
    // seconds, so a fast refusal is what proves no allocation happened.
    expect(microtime(true) - $started)->toBeLessThan(1.0);
    expect(is_file($p['dec']))->toBeFalse();

    $p['cleanup']();
});

// The bound is what this application WRITES, so both parameter sets it can
// write have to keep opening: a bound that refused a real backup would be a
// reader locked out of their own file.
it('still opens both parameter sets it writes — a passphrase backup and a key-sealed file', function (): void {
    $p = backupTmpPaths();
    $plaintext = random_bytes(10_000);
    file_put_contents($p['plain'], $plaintext);
    $enc = new BackupEncryptor;

    $enc->encrypt($p['plain'], $p['enc'], 'pw');
    $enc->decrypt($p['enc'], $p['dec'], 'pw');
    expect((string) file_get_contents($p['dec']))->toBe($plaintext);

    @unlink($p['dec']);

    $key = random_bytes(32);
    $enc->encryptWithKey($p['plain'], $p['enc'], $key);
    $enc->decrypt($p['enc'], $p['dec'], $key);
    expect((string) file_get_contents($p['dec']))->toBe($plaintext);

    $p['cleanup']();
});

it('detects a truncated backup (missing final tag)', function (): void {
    $p = backupTmpPaths();
    file_put_contents($p['plain'], random_bytes(200_000));
    $enc = new BackupEncryptor;
    $enc->encrypt($p['plain'], $p['enc'], 'pw');

    $bytes = (string) file_get_contents($p['enc']);
    file_put_contents($p['enc'], substr($bytes, 0, strlen($bytes) - 4000));

    expect(fn () => $enc->decrypt($p['enc'], $p['dec'], 'pw'))
        ->toThrow(BackupDecryptionException::class);

    $p['cleanup']();
});

it('rejects a file that is not a Beatrax encrypted backup', function (): void {
    $p = backupTmpPaths();
    file_put_contents($p['enc'], 'this is not an encrypted backup at all');

    expect(fn () => (new BackupEncryptor)->decrypt($p['enc'], $p['dec'], 'pw'))
        ->toThrow(BackupFormatException::class, 'bad file header');

    $p['cleanup']();
});

it('uses a 256-bit symmetric key (quantum-safe floor)', function (): void {
    // 256-bit key → 128-bit post-quantum security under Grover. If a future
    // libsodium ever shrank this, the scheme would drop below the documented
    // quantum-safe floor — fail loudly here.
    expect(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES * 8)->toBe(256);
});

// Failure paths: a real file will not fail a read halfway through on request,
// so the tests below drive those branches through a stream wrapper that serves
// real bytes and then refuses.

it('refuses to read a backup that is not there', function (): void {
    $p = backupTmpPaths();

    expect(fn () => (new BackupEncryptor)->decrypt($p['enc'], $p['dec'], 'pw'))
        ->toThrow(BackupIoException::class, 'Cannot read encrypted backup');

    $p['cleanup']();
});

// A file too short to hold even the magic. Distinct from the "not a Beatrax
// backup" case, which has enough bytes to compare and finds the wrong ones.
it('rejects a file that ends before the header does', function (): void {
    $p = backupTmpPaths();
    file_put_contents($p['enc'], 'BTR');

    expect(fn () => (new BackupEncryptor)->decrypt($p['enc'], $p['dec'], 'pw'))
        ->toThrow(BackupFormatException::class, 'ended unexpectedly');

    $p['cleanup']();
});

// The decrypt writes to a temp sibling and renames on success. A directory
// sitting at the destination makes that last rename fail with the plaintext
// already complete — the one failure that happens after all the crypto worked.
it('reports a destination it cannot rename onto, and leaves no plaintext', function (): void {
    $p = backupTmpPaths();
    file_put_contents($p['plain'], random_bytes(1_000));
    $enc = new BackupEncryptor;
    $enc->encrypt($p['plain'], $p['enc'], 'pw');

    mkdir($p['dec']);
    file_put_contents($p['dec'].'/occupied', 'x');

    expect(fn () => $enc->decrypt($p['enc'], $p['dec'], 'pw'))
        ->toThrow(BackupIoException::class, 'Could not finalize');

    expect(is_file($p['dec'].'.part'))->toBeFalse();

    @unlink($p['dec'].'/occupied');
    @rmdir($p['dec']);
    $p['cleanup']();
});

it('reports a read that fails while encrypting', function (): void {
    $p = backupTmpPaths();
    FailingStream::register();
    FailingStream::$data = random_bytes(1_000);
    FailingStream::$failOnRead = 1;

    expect(fn () => (new BackupEncryptor)->encrypt('beatraxfail://source', $p['enc'], 'pw'))
        ->toThrow(BackupIoException::class, 'Read error while encrypting backup.');

    FailingStream::reset();
    $p['cleanup']();
});

it('reports a write that reports zero bytes while encrypting', function (): void {
    $p = backupTmpPaths();
    file_put_contents($p['plain'], random_bytes(1_000));
    FailingStream::register();
    FailingStream::$failWrites = true;

    expect(fn () => (new BackupEncryptor)->encrypt($p['plain'], 'beatraxfail://sink', 'pw'))
        ->toThrow(BackupIoException::class, 'Write error on');

    FailingStream::reset();
    $p['cleanup']();
});

// Not covered: the `fread() === false` check inside the decrypt loop, and the
// SodiumException catch around init_pull. PHP turns a wrapper's failed read
// into a short read once its 8 KiB buffer is in play, and init_pull only
// raises for a wrong-length header that readExactly() already guarantees.

// The header is served as its own read so PHP's buffer is empty when the
// ciphertext loop asks for its first block — otherwise the buffered remainder
// is returned as a short read, the AEAD rejects it as corruption, and the test
// passes against a different branch than the one it names.
it('reports a read that fails on the first ciphertext block', function (): void {
    $p = backupTmpPaths();
    file_put_contents($p['plain'], random_bytes(200_000));
    $enc = new BackupEncryptor;
    $enc->encrypt($p['plain'], $p['enc'], 'pw');

    FailingStream::register();
    FailingStream::$data = (string) file_get_contents($p['enc']);
    // MAGIC(8) + salt(16) + opslimit(4) + memlimit(8) + stream header(24).
    FailingStream::$chunkSize = 60;
    FailingStream::$failOnRead = 2;

    expect(fn () => $enc->decrypt('beatraxfail://ciphertext', $p['dec'], 'pw'))
        ->toThrow(BackupIoException::class, 'Read error while decrypting backup.');

    FailingStream::reset();
    $p['cleanup']();
});

// Truncating on an exact block boundary is a different failure from truncating
// mid-block: the reader gets a clean end-of-file rather than a partial block,
// so it leaves the loop without ever seeing the FINAL tag.
it('detects a backup truncated on a block boundary', function (): void {
    $p = backupTmpPaths();
    file_put_contents($p['plain'], random_bytes(200_000));
    $enc = new BackupEncryptor;
    $enc->encrypt($p['plain'], $p['enc'], 'pw');

    $headerBytes = 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES + 4 + 8
        + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
    $blockBytes = 65536 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

    $bytes = (string) file_get_contents($p['enc']);
    file_put_contents($p['enc'], substr($bytes, 0, $headerBytes + $blockBytes));

    expect(fn () => $enc->decrypt($p['enc'], $p['dec'], 'pw'))
        ->toThrow(BackupDecryptionException::class, 'truncated');

    expect(is_file($p['dec']))->toBeFalse();

    $p['cleanup']();
});

// encrypt() opens the source before the destination, so a destination it
// cannot open leaves a source handle already open. It has to be closed on the
// way out or every failed encrypt leaks one.
it('closes the source handle when the destination cannot be opened', function (): void {
    $p = backupTmpPaths();
    file_put_contents($p['plain'], random_bytes(1_000));
    mkdir($p['enc']);

    $before = count(get_resources('stream'));

    expect(fn () => (new BackupEncryptor)->encrypt($p['plain'], $p['enc'], 'pw'))
        ->toThrow(BackupIoException::class, 'Cannot write encrypted backup');

    expect(count(get_resources('stream')))->toBe($before);

    @rmdir($p['enc']);
    $p['cleanup']();
});
