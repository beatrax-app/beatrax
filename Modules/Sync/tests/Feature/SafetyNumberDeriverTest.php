<?php

declare(strict_types=1);

use Modules\Sync\Internal\Pairing\Bip39WordList;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;

function safetyDeriver(): SafetyNumberDeriver
{
    return new SafetyNumberDeriver(Bip39WordList::WORDS);
}

it('derives an identical 6-word safety-number regardless of key order (order-independent)', function (): void {
    $pubA = random_bytes(32);
    $pubB = random_bytes(32);

    $deriver = safetyDeriver();

    $forward = $deriver->derive($pubA, $pubB);
    $reverse = $deriver->derive($pubB, $pubA);

    expect($forward)->toBe($reverse);
});

it('returns exactly six BIP39 words drawn from the canonical wordlist', function (): void {
    $words = safetyDeriver()->derive(random_bytes(32), random_bytes(32));

    expect($words)->toHaveCount(6);

    foreach ($words as $word) {
        expect(in_array($word, Bip39WordList::WORDS, true))->toBeTrue();
    }
});

it('is deterministic — identical key inputs yield identical words', function (): void {
    $pubA = str_repeat("\x11", 32);
    $pubB = str_repeat("\x22", 32);

    $deriver = safetyDeriver();

    expect($deriver->derive($pubA, $pubB))->toBe($deriver->derive($pubA, $pubB));
});
