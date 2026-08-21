<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\AppLockKeyWrap;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;

// The blob is self-contained — secret || nonce || ciphertext — because the
// enclave stores it as one opaque value.

function blobCodec(): BiometricKeyBlobCodec
{
    return new BiometricKeyBlobCodec(new AppLockKeyWrap);
}

it('round-trips a data key through wrap()/unwrap()', function (): void {
    $codec = blobCodec();
    $dataKey = random_bytes(32);

    $blob = $codec->wrap($dataKey);

    expect($codec->unwrap($blob))->toBe($dataKey);
});

it('produces a blob that is not the raw data key and carries a fresh secret each time', function (): void {
    $codec = blobCodec();
    $dataKey = str_repeat("\x2a", 32);

    $a = $codec->wrap($dataKey);
    $b = $codec->wrap($dataKey);

    // The secret prefix is random per call, so two wraps of one key differ.
    expect($a)->not->toBe($dataKey)
        ->and($a)->not->toBe($b)
        ->and(strlen($a))->toBeGreaterThan(32);
});

it('returns null for a tampered blob', function (): void {
    $codec = blobCodec();
    $blob = $codec->wrap(random_bytes(32));

    // Past the secret and the nonce, so this lands in the ciphertext.
    $tampered = $blob;
    $tampered[strlen($tampered) - 1] = $tampered[strlen($tampered) - 1] === "\x00" ? "\x01" : "\x00";

    expect($codec->unwrap($tampered))->toBeNull();
});

it('returns null for a too-short blob', function (): void {
    expect(blobCodec()->unwrap('short'))->toBeNull();
});

it('returns null when the secret half is wrong (blob from a different secret)', function (): void {
    $codec = blobCodec();
    $blob = $codec->wrap(random_bytes(32));

    // A corrupt secret prefix must fail closed rather than return garbage.
    $corrupted = str_repeat("\x00", 32).substr($blob, 32);

    expect($codec->unwrap($corrupted))->toBeNull();
});
