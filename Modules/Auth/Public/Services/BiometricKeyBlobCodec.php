<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Services;

use Modules\Auth\Internal\Lock\AppLockKeyWrap;

final class BiometricKeyBlobCodec
{
    public function __construct(
        private readonly AppLockKeyWrap $keyWrap,
    ) {}

    /**
     * @param  string  $dataKey  The raw data-key bytes to protect.
     * @return string `secret || nonce||ciphertext` raw bytes.
     */
    public function wrap(string $dataKey): string
    {
        $secret = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $wrapped = $this->keyWrap->wrap($dataKey, $secret);

        // A strict decode of wrap()'s own output cannot fail; guarded rather
        // than concatenating a bool.
        $wrappedBytes = base64_decode($wrapped, strict: true);
        $blob = $wrappedBytes === false ? $secret : $secret.$wrappedBytes;
        sodium_memzero($secret);

        return $blob;
    }

    // Null when the blob is malformed, tampered, or not one this codec made.
    public function unwrap(string $blob): ?string
    {
        if (strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }

        $secret = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $wrappedBytes = substr($blob, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        $dataKey = $this->keyWrap->unwrap(base64_encode($wrappedBytes), $secret);
        sodium_memzero($secret);

        return $dataKey === false ? null : $dataKey;
    }
}
