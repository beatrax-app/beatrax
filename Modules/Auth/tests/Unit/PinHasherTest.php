<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\PinHasher;

it('hashes a PIN and verifies the correct PIN successfully', function (): void {
    $hasher = new PinHasher;

    $hash = $hasher->hash('123456');
    expect($hasher->verify('123456', $hash))->toBeTrue();
});

it('rejects a wrong PIN', function (): void {
    $hasher = new PinHasher;

    $hash = $hasher->hash('123456');
    expect($hasher->verify('654321', $hash))->toBeFalse();
});

it('produces an Argon2id hash string', function (): void {
    $hasher = new PinHasher;

    $hash = $hasher->hash('123456');
    expect($hash)->toStartWith('$argon2id$');
});
