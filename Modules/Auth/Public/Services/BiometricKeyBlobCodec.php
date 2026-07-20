<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Services;

use Modules\Auth\Internal\Lock\AppLockKeyWrap;

/**
 * Public seam that wraps / unwraps a data key into the self-contained
 * "biometric blob" format, so a non-Auth module (the Mobile cold-start
 * biometric vault) can protect the data key with the SAME primitive the
 * desktop WebAuthn path uses, without importing `Modules\Auth\Internal\*`.
 *
 * Blob format (identical to WebAuthnBiometricService's D-14 blob):
 *
 *     [ 32-byte random biometric wrap secret (BWS) ] || [ nonce || ciphertext ]
 *
 * The BWS is the secretbox key; the tail is `AppLockKeyWrap::wrap(dataKey, BWS)`
 * decoded to raw bytes. Storing a fresh random secret alongside the wrapped key
 * (rather than the raw data key) means the enclave-gated entry never holds the
 * data key in plaintext form; recovering it requires both halves of this blob,
 * which only leave the secure enclave after a successful biometric.
 *
 * This class performs NO storage and NO biometric interaction — it is pure
 * crypto glue. The caller stores/reads the blob (the Mobile vault puts it in a
 * biometric-gated Keychain / Keystore entry) and gates the biometric.
 */
final class BiometricKeyBlobCodec
{
    public function __construct(
        private readonly AppLockKeyWrap $keyWrap,
    ) {}

    /**
     * Wrap a data key into a fresh biometric blob under a new random secret.
     *
     * @param  string  $dataKey  The raw data-key bytes to protect.
     * @return string `BWS || nonce||ciphertext` raw bytes.
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

    /**
     * Reverse {@see self::wrap()}: recover the data key from a biometric blob,
     * or null when the blob is malformed / tampered / not one we produced.
     */
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
