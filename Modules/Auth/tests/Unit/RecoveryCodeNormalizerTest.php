<?php

declare(strict_types=1);

use Modules\Auth\Internal\Recovery\RecoveryCodeNormalizer;

it('uppercases the input and strips hyphens and whitespace', function (): void {
    $normalizer = new RecoveryCodeNormalizer;

    expect($normalizer->normalize('a2bj-xk9m pq7n  rx4f-v8hd'))
        ->toBe('A2BJXK9MPQ7NRX4FV8HD');
});

it('drops characters outside the [A-NP-Z2-9] alphabet', function (): void {
    $normalizer = new RecoveryCodeNormalizer;

    // O, I, L, 0 and 1 are ambiguous and never part of a real code, so a user
    // who types one must not poison the comparison.
    expect($normalizer->normalize('A2BJ-XK9M-PQ7N-RX4F-V8HD!@#'))
        ->toBe('A2BJXK9MPQ7NRX4FV8HD');

    expect($normalizer->normalize('o0i1l-A2BJ'))->toBe('A2BJ');
});

it('returns an empty string when nothing in the input is a code character', function (): void {
    $normalizer = new RecoveryCodeNormalizer;

    expect($normalizer->normalize('---   ---'))->toBe('');
});
