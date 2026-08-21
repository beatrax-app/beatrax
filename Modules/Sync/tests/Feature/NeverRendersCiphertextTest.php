<?php

declare(strict_types=1);

use Modules\Sync\Public\Services\SensitiveColumnCodec;

// A device holding encrypted rows without the key must show nothing rather
// than base64: a phone that lost its keyring to an app update rendered all 124
// synced descriptions as blobs. Durable keyring storage stops that recurring;
// this is the backstop, and it must never blank real text.

function codecLooksLikeCiphertext(string $value): bool
{
    $method = new ReflectionMethod(SensitiveColumnCodec::class, 'looksLikeCiphertext');
    $method->setAccessible(true);

    return (bool) $method->invoke(null, $value);
}

it('treats a real ciphertext envelope as unreadable', function (): void {
    // 24-byte nonce + 32 bytes of body, the shape OpLogFieldCrypto emits.
    $envelope = base64_encode(random_bytes(24).random_bytes(32));

    expect(codecLooksLikeCiphertext($envelope))->toBeTrue();
});

it('never blanks a bank descriptor', function (string $description): void {
    expect(codecLooksLikeCiphertext($description))->toBeFalse();
})->with([
    'plain merchant' => 'ALBERT HEIJN 1234 EINDHOVEN',
    'with punctuation' => 'SEPA iDEAL BOL.COM B.V.',
    'accented' => 'CAFÉ ZURICH',
    'single word' => 'SPOTIFY',
    'iban-like' => 'NL91ABNA0417164300',
    'short base64-ish word' => 'Netflix',
    'digits only' => '1234567890',
    'cyrillic' => 'ОЩАДБАНК',
]);

it('leaves a short base64-looking token alone', function (): void {
    // Strict base64 by charset, but far too short to be nonce + MAC — so it
    // is far likelier to be a real reference than an envelope.
    expect(codecLooksLikeCiphertext('AB12cd'))->toBeFalse();
});
