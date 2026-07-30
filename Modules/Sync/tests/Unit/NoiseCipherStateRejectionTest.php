<?php

declare(strict_types=1);

use Modules\Sync\Internal\Exceptions\NoiseDecryptionFailedException;
use Modules\Sync\Internal\Transport\Noise\NoiseCipherState;

/*
 * What the transport cipher does with a message it cannot authenticate.
 *
 * A decryption failure here is a statement about the peer, not about this
 * build: the ciphertext was altered in flight, replayed, or sent under a
 * different key. That is why it is not the same type an encryption failure
 * raises — one is a security event and the other is a bug report.
 */

function noiseKey(): string
{
    return str_repeat("\x2a", 32);
}

it('refuses a ciphertext whose tag does not verify', function (int $flipAt): void {
    $sealed = (new NoiseCipherState(noiseKey()))->encrypt('ledger bytes');
    $tampered = $sealed;
    $tampered[$flipAt] = chr(ord($tampered[$flipAt]) ^ 0xFF);

    expect(fn () => (new NoiseCipherState(noiseKey()))->decrypt($tampered))
        ->toThrow(NoiseDecryptionFailedException::class, 'invalid ciphertext or wrong key');
})->with([
    'first byte of the body' => [0],
    'last byte of the tag' => [-1],
]);

it('refuses a ciphertext sealed under a different key', function (): void {
    $sealed = (new NoiseCipherState(noiseKey()))->encrypt('ledger bytes');
    $otherKey = str_repeat("\x7f", 32);

    expect(fn () => (new NoiseCipherState($otherKey))->decrypt($sealed))
        ->toThrow(NoiseDecryptionFailedException::class, 'invalid ciphertext or wrong key');
});

// The associated data is authenticated but not encrypted, so a mismatch means
// the framing around the message was changed even though the body was not.
it('refuses a ciphertext whose associated data does not match', function (): void {
    $sealed = (new NoiseCipherState(noiseKey()))->encrypt('ledger bytes', 'frame-a');

    expect(fn () => (new NoiseCipherState(noiseKey()))->decrypt($sealed, 'frame-b'))
        ->toThrow(NoiseDecryptionFailedException::class);
});

it('round-trips a message the peer did not touch', function (): void {
    $sealed = (new NoiseCipherState(noiseKey()))->encrypt('ledger bytes', 'frame-a');

    expect((new NoiseCipherState(noiseKey()))->decrypt($sealed, 'frame-a'))->toBe('ledger bytes');
});
