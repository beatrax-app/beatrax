<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\AppLockKdf;

it('derives a key of exactly SODIUM_CRYPTO_SECRETBOX_KEYBYTES length', function (): void {
    $kdf = new AppLockKdf;

    $salt = $kdf->generateSalt();
    $key = $kdf->deriveWrapKey('my-pin', $salt);

    expect(strlen($key))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    sodium_memzero($key);
});

it('derives the same key from the same PIN and salt', function (): void {
    $kdf = new AppLockKdf;

    $salt = $kdf->generateSalt();
    $key1 = $kdf->deriveWrapKey('my-pin', $salt);
    $key2 = $kdf->deriveWrapKey('my-pin', $salt);

    expect($key1)->toBe($key2);
    sodium_memzero($key1);
    sodium_memzero($key2);
});

it('derives different keys from different salts', function (): void {
    $kdf = new AppLockKdf;

    $salt1 = $kdf->generateSalt();
    $salt2 = $kdf->generateSalt();
    $key1 = $kdf->deriveWrapKey('my-pin', $salt1);
    $key2 = $kdf->deriveWrapKey('my-pin', $salt2);

    expect($key1)->not->toBe($key2);
    sodium_memzero($key1);
    sodium_memzero($key2);
});

it('generates a salt of SODIUM_CRYPTO_PWHASH_SALTBYTES length', function (): void {
    $kdf = new AppLockKdf;

    $salt = $kdf->generateSalt();
    expect(strlen($salt))->toBe(SODIUM_CRYPTO_PWHASH_SALTBYTES);
});

it('generates different salts on each call', function (): void {
    $kdf = new AppLockKdf;

    $salt1 = $kdf->generateSalt();
    $salt2 = $kdf->generateSalt();

    expect($salt1)->not->toBe($salt2);
});
