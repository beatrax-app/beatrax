<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Services;

use Modules\Auth\Internal\Lock\AppLockKeyWrap;

/**
 * @link ../../../../.docs/features/auth/architecture.md
 */
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

        // wrap() base64-encodes nonce||ciphertext internally, so a strict
        // decode of its own output cannot fail; guard anyway rather than
        // concatenate a bool. A degenerate secret-only blob (32 bytes) would
        // fail unwrap()'s length guard closed.
        $wrappedBytes = base64_decode($wrapped, strict: true);
        $blob = $wrappedBytes === false ? $secret : $secret.$wrappedBytes;
        sodium_memzero($secret);

        return $blob;
    }

    // Reverses wrap(): returns null when the blob is malformed, tampered,
    // or not one this codec produced.
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
