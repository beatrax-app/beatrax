<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use InvalidArgumentException;

final class OpLogFieldCrypto
{
    // Encrypts $plaintext under $rawGdkKey with $associatedData bound into
    // the AEAD authentication tag. Returns base64(nonce||ciphertext).
    /**
     * @throws InvalidArgumentException when $rawGdkKey is empty (non-empty-string guard).
     */
    public function encrypt(string $plaintext, string $rawGdkKey, string $associatedData): string
    {
        if ($rawGdkKey === '') {
            throw new InvalidArgumentException('OpLogFieldCrypto::encrypt — rawGdkKey must not be empty.');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $associatedData,
            $nonce,
            $rawGdkKey,
        );

        return base64_encode($nonce.$ciphertext);
    }

    // Returns `false` (NEVER throws, never garbage) when $stored is not valid
    // strict base64, the decoded blob is too short to contain a ciphertext,
    // or AEAD authentication fails. Callers MUST use a strict `=== false`
    // check — never `!$result` — and route a false result to the quarantine/lock path.
    /**
     * @throws InvalidArgumentException when $rawGdkKey is empty.
     */
    public function decrypt(string $stored, string $rawGdkKey, string $associatedData): string|false
    {
        if ($rawGdkKey === '') {
            throw new InvalidArgumentException('OpLogFieldCrypto::decrypt — rawGdkKey must not be empty.');
        }

        $decoded = base64_decode($stored, strict: true);
        if ($decoded === false) {
            return false;
        }

        if (strlen($decoded) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            return false;
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        // sodium_crypto_aead_xchacha20poly1305_ietf_decrypt returns false on
        // any authentication failure — false not garbage.
        return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $associatedData,
            $nonce,
            $rawGdkKey,
        );
    }
}
