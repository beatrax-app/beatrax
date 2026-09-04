<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\AppLockKdf;
use Modules\Auth\Internal\Lock\PinHasher;

// AppLockKdfTest and PinHasherTest exercise these two at the suite's cheap
// cost, which says nothing about what a real install derives at. This file
// resolves both out of an application booted the way bootstrap/app.php boots
// one — no substituted binding — and pins what comes back.

it('derives the pinned wrap key for the pinned PIN and salt', function (): void {
    $kdf = $this->createApplication()->make(AppLockKdf::class);

    $key = $kdf->deriveWrapKey('beatrax-app-lock-vector', hex2bin('000102030405060708090a0b0c0d0e0f'));

    // A wrap key is what opens the data key, so this vector is the one number
    // a weakened app-lock KDF would change. It fails on a lowered opslimit or
    // memlimit, a swapped algorithm, and a shortened key.
    expect(bin2hex($key))
        ->toBe('d598d3a44f9525b8f230eade18c4cb21b04789e2b9da3c035192c2e087b993e8');
});

it('writes the shipped parameters into the PIN hash itself', function (): void {
    $hasher = $this->createApplication()->make(PinHasher::class);

    // libsodium encodes memory in KiB and passes as t, so 256 MiB / 3 passes
    // reads as m=262144,t=3. The salt that follows is random per call.
    expect($hasher->hash('246810'))->toStartWith('$argon2id$v=19$m=262144,t=3,p=1$');
});

// What makes the substitution safe for stored data: a verifier reads the
// parameters out of the hash it is checking, not out of its own cost. So a row
// a real install wrote opens in the suite, and vice versa.
it('verifies across the two costs in both directions', function (): void {
    $shipped = $this->createApplication()->make(PinHasher::class);
    $cheap = $this->app->make(PinHasher::class);

    expect($cheap->verify('246810', $shipped->hash('246810')))->toBeTrue()
        ->and($shipped->verify('246810', $cheap->hash('246810')))->toBeTrue()
        ->and($cheap->verify('999999', $shipped->hash('246810')))->toBeFalse();
});
