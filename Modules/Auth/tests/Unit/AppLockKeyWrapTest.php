<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\AppLockKeyWrap;

it('round-trips a 32-byte data key through wrap and unwrap', function (): void {
    $wrapper = new AppLockKeyWrap;

    $dataKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $wrapKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

    $stored = $wrapper->wrap($dataKey, $wrapKey);
    $recovered = $wrapper->unwrap($stored, $wrapKey);

    expect($recovered)->toBe($dataKey);
});

it('returns false when unwrapping with a wrong wrap key', function (): void {
    $wrapper = new AppLockKeyWrap;

    $dataKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $wrapKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $wrongKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

    $stored = $wrapper->wrap($dataKey, $wrapKey);
    $result = $wrapper->unwrap($stored, $wrongKey);

    expect($result)->toBeFalse();
});

it('returns false (not an exception) when unwrapping a truncated blob', function (): void {
    $wrapper = new AppLockKeyWrap;

    $wrapKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $truncated = base64_encode('short');

    $result = $wrapper->unwrap($truncated, $wrapKey);

    expect($result)->toBeFalse();
});

it('returns false (not an exception) when unwrapping garbage input', function (): void {
    $wrapper = new AppLockKeyWrap;

    $wrapKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

    $result = $wrapper->unwrap('not-valid-base64!!!', $wrapKey);

    expect($result)->toBeFalse();
});
