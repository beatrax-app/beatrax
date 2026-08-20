<?php

declare(strict_types=1);

use Modules\Core\Public\Services\PassthroughSecretShield;

it('returns the plaintext unchanged from protect()', function (): void {
    $shield = new PassthroughSecretShield;

    expect($shield->protect('GOCSPX-super-secret'))->toBe('GOCSPX-super-secret');
});

it('round-trips any bytes through protect()/reveal()', function (): void {
    $shield = new PassthroughSecretShield;
    $blob = random_bytes(64);

    expect($shield->reveal($shield->protect($blob)))->toBe($blob);
});

it('reveal() is identity for an unshielded value', function (): void {
    $shield = new PassthroughSecretShield;

    expect($shield->reveal('legacy-plaintext'))->toBe('legacy-plaintext');
});
