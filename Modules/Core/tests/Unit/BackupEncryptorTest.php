<?php

declare(strict_types=1);

use Modules\Core\Public\Services\BackupEncryptor;

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
    // The ciphertext must not contain the plaintext anywhere.
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
        ->toThrow(RuntimeException::class);

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
        ->toThrow(RuntimeException::class);

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
        ->toThrow(RuntimeException::class, 'outside the accepted range');
    expect(is_file($p['dec']))->toBeFalse();

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
        ->toThrow(RuntimeException::class);

    $p['cleanup']();
});

it('rejects a file that is not a beatrax encrypted backup', function (): void {
    $p = backupTmpPaths();
    file_put_contents($p['enc'], 'this is not an encrypted backup at all');

    expect(fn () => (new BackupEncryptor)->decrypt($p['enc'], $p['dec'], 'pw'))
        ->toThrow(RuntimeException::class, 'bad file header');

    $p['cleanup']();
});

it('uses a 256-bit symmetric key (quantum-safe floor)', function (): void {
    // 256-bit key → 128-bit post-quantum security under Grover. If a future
    // libsodium ever shrank this, the scheme would drop below the documented
    // quantum-safe floor — fail loudly here.
    expect(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES * 8)->toBe(256);
});
